<?php

namespace Hibla\Http\Handlers;

use Psr\SimpleCache\CacheInterface;
use Hibla\Http\Config\HttpConfigLoader;
use Hibla\EventLoop\EventLoop;
use Hibla\Http\CacheConfig;
use Hibla\Http\Exceptions\HttpException;
use Hibla\Http\Response;
use Hibla\Http\RetryConfig;
use Hibla\Http\SSE\SSEReconnectConfig;
use Hibla\Http\StreamingResponse;
use Hibla\Http\Traits\FetchOptionTrait;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

use function Hibla\async;

/**
 * Handler for fetch-style HTTP requests with advanced options support.
 *
 * This class provides a flexible, fetch-like interface for making HTTP requests
 * with support for streaming, downloads, retry logic, and caching.
 */
class FetchHandler
{
    use FetchOptionTrait;

    private StreamingHandler $streamingHandler;
    private static ?CacheInterface $defaultCache = null;
    private SSEHandler $sseHandler;

    public function __construct(?StreamingHandler $streamingHandler = null, ?SSEHandler $sseHandler = null)
    {
        $this->streamingHandler = $streamingHandler ?? new StreamingHandler;
        $this->sseHandler = $sseHandler ?? new SSEHandler($this->streamingHandler);
    }

    /**
     * A flexible, fetch-like method for making HTTP requests with streaming support.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  An associative array of request options.
     * @return PromiseInterface<Response>|CancellablePromiseInterface<StreamingResponse>|CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>}> A promise that resolves with a Response, StreamingResponse, or download metadata.
     */
    public function fetch(string $url, array $options = []): PromiseInterface|CancellablePromiseInterface
    {
        if ($this->isDownloadRequested($options)) {
            return $this->fetchDownload($url, $options);
        }

        if ($this->isSSERequested($options)) {
            $sseConfig = $this->extractSSEConfig($options);

            return $this->fetchSSE(
                $url,
                $options,
                $sseConfig['onEvent'],
                $sseConfig['onError'],
                $sseConfig['reconnectConfig']
            );
        }

        $isStreaming = $this->isStreamingRequested($options);
        $onChunk = $this->extractOnChunkCallback($options);

        if ($isStreaming) {
            return $this->fetchStream($url, $options, $onChunk);
        }

        $retryConfig = $this->extractRetryConfig($options);
        $cacheConfig = $this->extractCacheConfig($options);

        $curlOptions = $this->normalizeFetchOptions($url, $options);

        if ($cacheConfig !== null) {
            return $this->sendRequestWithCache($url, $curlOptions, $cacheConfig, $retryConfig);
        } elseif ($retryConfig !== null) {
            return $this->fetchWithRetry($url, $curlOptions, $retryConfig);
        }

        return $this->executeBasicFetch($url, $curlOptions);
    }

    /**
     * Sends a request with automatic retry logic on failure.
     *
     * @param  string  $url  The target URL.
     * @param  array<int, mixed>  $options  An array of cURL options.
     * @param  RetryConfig  $retryConfig  Configuration object for retry behavior.
     * @return PromiseInterface<Response> A promise that resolves with a Response object or rejects with an HttpException on final failure.
     */
    public function fetchWithRetry(string $url, array $options, RetryConfig $retryConfig): PromiseInterface
    {
        /** @var CancellablePromise<Response> $promise */
        $promise = new CancellablePromise;
        $attempt = 0;
        $totalAttempts = 0;
        /** @var string|null $requestId */
        $requestId = null;

        $cookieJar = $options['_cookie_jar'] ?? null;
        unset($options['_cookie_jar']);

        $executeRequest = function () use ($url, $options, $retryConfig, $promise, &$attempt, &$totalAttempts, &$requestId, &$executeRequest, $cookieJar) {
            $totalAttempts++;

            $requestId = EventLoop::getInstance()->addHttpRequest(
                $url,
                $options,
                function (?string $error, ?string $responseBody, ?int $httpCode, array $headers = [], ?string $httpVersion = null) use ($retryConfig, $promise, &$attempt, &$totalAttempts, &$executeRequest, $cookieJar) {

                    if ($promise->isCancelled()) {
                        return;
                    }

                    $isRetryable = ($error !== null && $retryConfig->isRetryableError($error)) ||
                        ($httpCode !== null && in_array($httpCode, $retryConfig->retryableStatusCodes, true));

                    if ($isRetryable && $attempt < $retryConfig->maxRetries) {
                        $attempt++;
                        $delay = $retryConfig->getDelay($attempt);

                        EventLoop::getInstance()->addTimer($delay, $executeRequest);

                        return;
                    }

                    if ($error !== null) {
                        $promise->reject(new HttpException("HTTP Request failed after {$totalAttempts} attempts: {$error}"));
                    } elseif ($isRetryable) {
                        $promise->reject(new HttpException("HTTP Request failed with status {$httpCode} after {$totalAttempts} attempts."));
                    } else {
                        /** @var array<string, array<string>|string> $normalizedHeaders */
                        $normalizedHeaders = $this->normalizeHeaders($headers);
                        $responseObj = new Response($responseBody ?? '', $httpCode ?? 0, $normalizedHeaders);

                        if ($httpVersion !== null) {
                            $responseObj->setHttpVersion($httpVersion);
                        }

                        if ($cookieJar !== null) {
                            $responseObj->applyCookiesToJar($cookieJar);
                        }

                        $promise->resolve($responseObj);
                    }
                }
            );
        };

        $executeRequest();

        $promise->setCancelHandler(function () use (&$requestId) {
            if ($requestId !== null) {
                EventLoop::getInstance()->cancelHttpRequest($requestId);
            }
        });

        return $promise;
    }

    /**
     * Handles download requests through fetch.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  Request options.
     * @return CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>}> A promise that resolves with download metadata.
     */
    private function fetchDownload(string $url, array $options): CancellablePromiseInterface
    {
        $destination = $options['download'] ?? $options['save_to'] ?? null;

        if (! is_string($destination)) {
            throw new \InvalidArgumentException('Download destination must be a string path');
        }

        // Normalize options to cURL format
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        return $this->streamingHandler->downloadFile($url, $destination, $curlOptions);
    }

    /**
     * Checks if download is requested in options.
     *
     * @param  array<int|string, mixed>  $options  The options array.
     * @return bool True if download is requested.
     */
    private function isDownloadRequested(array $options): bool
    {
        return isset($options['download']) || isset($options['save_to']);
    }

    /**
     * Handles streaming requests through fetch.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  Request options.
     * @param  callable|null  $onChunk  Optional chunk callback.
     * @return CancellablePromiseInterface<StreamingResponse> A promise that resolves with a StreamingResponse.
     */
    private function fetchStream(string $url, array $options, ?callable $onChunk = null): CancellablePromiseInterface
    {
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        // Remove headers that might interfere with streaming
        unset($curlOptions[CURLOPT_HEADER]);

        return $this->streamingHandler->streamRequest($url, $curlOptions, $onChunk);
    }

    /**
     * Checks if streaming is requested in options.
     *
     * @param  array<int|string, mixed>  $options  The options array.
     * @return bool True if streaming is requested.
     */
    private function isStreamingRequested(array $options): bool
    {
        return isset($options['stream']) && $options['stream'] === true;
    }

    /**
     * Extracts the onChunk callback from options.
     *
     * @param  array<int|string, mixed>  $options  The options array.
     * @return callable|null The chunk callback if provided.
     */
    private function extractOnChunkCallback(array $options): ?callable
    {
        if (isset($options['on_chunk']) && is_callable($options['on_chunk'])) {
            return $options['on_chunk'];
        }

        // Also support 'onChunk' for consistency with Request builder
        if (isset($options['onChunk']) && is_callable($options['onChunk'])) {
            return $options['onChunk'];
        }

        return null;
    }

    /**
     * Executes basic fetch without advanced features
     *
     * @param  array<int, mixed>  $curlOptions
     * @return PromiseInterface<Response>
     */
    private function executeBasicFetch(string $url, array $curlOptions): PromiseInterface
    {
        /** @var CancellablePromise<Response> $promise */
        $promise = new CancellablePromise;

        $cookieJar = $curlOptions['_cookie_jar'] ?? null;
        unset($curlOptions['_cookie_jar']);

        $requestId = EventLoop::getInstance()->addHttpRequest(
            $url,
            $curlOptions,
            function (?string $error, ?string $response, ?int $httpCode, array $headers = [], ?string $httpVersion = null) use ($promise, $cookieJar) {
                if ($promise->isCancelled()) {
                    return;
                }

                if ($error !== null) {
                    $promise->reject(new HttpException("HTTP Request failed: {$error}"));
                } else {
                    $normalizedHeaders = $this->normalizeHeaders($headers);
                    $responseObj = new Response($response ?? '', $httpCode ?? 0, $normalizedHeaders);

                    if ($httpVersion !== null) {
                        $responseObj->setHttpVersion($httpVersion);
                    }

                    if ($cookieJar !== null) {
                        $responseObj->applyCookiesToJar($cookieJar);
                    }

                    $promise->resolve($responseObj);
                }
            }
        );

        $promise->setCancelHandler(function () use ($requestId) {
            EventLoop::getInstance()->cancelHttpRequest($requestId);
        });

        return $promise;
    }

    /**
     * Sends a request with caching support.
     *
     * @param  string  $url  The target URL.
     * @param  array<int, mixed>  $curlOptions  cURL options for the request.
     * @param  CacheConfig  $cacheConfig  Cache configuration.
     * @param  RetryConfig|null  $retryConfig  Optional retry configuration.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    private function sendRequestWithCache(string $url, array $curlOptions, CacheConfig $cacheConfig, ?RetryConfig $retryConfig = null): PromiseInterface
    {
        if (($curlOptions[CURLOPT_CUSTOMREQUEST] ?? 'GET') !== 'GET') {
            return $this->dispatchRequest($url, $curlOptions, $retryConfig);
        }

        $cache = $cacheConfig->cache ?? self::getDefaultCache();
        $cacheKey = $this->generateCacheKey($url);

        /** @var PromiseInterface<Response> */
        return async(function () use ($cache, $cacheKey, $url, $curlOptions, $cacheConfig, $retryConfig): Response {
            /** @var array{body: string, status: int, headers: array<string, array<string>|string>, expires_at: int}|null $cachedItem */
            $cachedItem = $cache->get($cacheKey);

            if (is_array($cachedItem) && isset($cachedItem['expires_at']) && is_int($cachedItem['expires_at']) && time() < $cachedItem['expires_at']) {
                if (isset($cachedItem['body'], $cachedItem['status'], $cachedItem['headers']) && is_string($cachedItem['body']) && is_int($cachedItem['status']) && is_array($cachedItem['headers'])) {
                    return new Response($cachedItem['body'], $cachedItem['status'], $cachedItem['headers']);
                }
            }

            if (is_array($cachedItem) && $cacheConfig->respectServerHeaders && isset($cachedItem['headers']) && is_array($cachedItem['headers'])) {
                /** @var array<string> $httpHeaders */
                $httpHeaders = [];
                if (isset($curlOptions[CURLOPT_HTTPHEADER]) && is_array($curlOptions[CURLOPT_HTTPHEADER])) {
                    $httpHeaders = $curlOptions[CURLOPT_HTTPHEADER];
                }

                $cachedHeaders = $cachedItem['headers'];

                if (isset($cachedHeaders['etag'])) {
                    $etagValue = $cachedHeaders['etag'];
                    if (is_string($etagValue)) {
                        $etag = $etagValue;
                    } elseif (is_array($etagValue) && isset($etagValue[0]) && is_string($etagValue[0])) {
                        $etag = $etagValue[0];
                    } else {
                        $etag = null;
                    }

                    if ($etag !== null) {
                        $httpHeaders[] = 'If-None-Match: ' . $etag;
                    }
                }

                if (isset($cachedHeaders['last-modified'])) {
                    $lastModifiedValue = $cachedHeaders['last-modified'];
                    if (is_string($lastModifiedValue)) {
                        $lastModified = $lastModifiedValue;
                    } elseif (is_array($lastModifiedValue) && isset($lastModifiedValue[0]) && is_string($lastModifiedValue[0])) {
                        $lastModified = $lastModifiedValue[0];
                    } else {
                        $lastModified = null;
                    }

                    if ($lastModified !== null) {
                        $httpHeaders[] = 'If-Modified-Since: ' . $lastModified;
                    }
                }

                $curlOptions[CURLOPT_HTTPHEADER] = $httpHeaders;
            }

            $response = await($this->dispatchRequest($url, $curlOptions, $retryConfig));

            if ($response->status() === 304 && is_array($cachedItem)) {
                $newExpiry = $this->calculateExpiry($response, $cacheConfig);
                $cachedItem['expires_at'] = $newExpiry;
                $cache->set($cacheKey, $cachedItem, $newExpiry > time() ? $newExpiry - time() : 0);

                if (isset($cachedItem['body'], $cachedItem['headers']) && is_string($cachedItem['body']) && is_array($cachedItem['headers'])) {
                    return new Response($cachedItem['body'], 200, $cachedItem['headers']);
                }
            }

            if ($response->ok()) {
                $expiry = $this->calculateExpiry($response, $cacheConfig);
                if ($expiry > time()) {
                    $ttl = $expiry - time();
                    $cache->set($cacheKey, [
                        'body' => (string) $response->getBody(),
                        'status' => $response->status(),
                        'headers' => $response->getHeaders(),
                        'expires_at' => $expiry,
                    ], $ttl);
                }
            }

            return $response;
        });
    }

    /**
     * Checks if SSE is requested in options.
     *
     * @param  array<int|string, mixed>  $options  The options array
     * @return bool True if SSE is requested
     */
    private function isSSERequested(array $options): bool
    {
        return isset($options['sse']) && $options['sse'] === true;
    }

    /**
     * Extract SSE callbacks from options.
     *
     * @param  array<int|string, mixed>  $options  The options array
     * @return array{onEvent: callable|null, onError: callable|null}
     */
    private function extractSSECallbacks(array $options): array
    {
        $onEvent = null;
        $onError = null;

        if (isset($options['on_event']) && is_callable($options['on_event'])) {
            $onEvent = $options['on_event'];
        } elseif (isset($options['onEvent']) && is_callable($options['onEvent'])) {
            $onEvent = $options['onEvent'];
        }

        if (isset($options['on_error']) && is_callable($options['on_error'])) {
            $onError = $options['on_error'];
        } elseif (isset($options['onError']) && is_callable($options['onError'])) {
            $onError = $options['onError'];
        }

        return ['onEvent' => $onEvent, 'onError' => $onError];
    }

    /**
     * Handles SSE requests through fetch.
     */
    private function fetchSSE(
        string $url,
        array $options,
        ?callable $onEvent = null,
        ?callable $onError = null,
        ?SSEReconnectConfig $reconnectConfig = null
    ): CancellablePromiseInterface {
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        return $this->sseHandler->connect($url, $curlOptions, $onEvent, $onError, $reconnectConfig);
    }

    /**
     * Extract SSE configuration from options.
     */
    private function extractSSEConfig(array $options): array
    {
        $callbacks = $this->extractSSECallbacks($options);
        $reconnectConfig = $this->extractSSEReconnectConfig($options);

        return [
            'onEvent' => $callbacks['onEvent'],
            'onError' => $callbacks['onError'],
            'reconnectConfig' => $reconnectConfig,
        ];
    }

    /**
     * Extract SSE reconnection config from options.
     */
    private function extractSSEReconnectConfig(array $options): ?SSEReconnectConfig
    {
        if (! isset($options['reconnect'])) {
            return null;
        }

        $reconnect = $options['reconnect'];

        if ($reconnect === true) {
            return new SSEReconnectConfig;
        }

        if ($reconnect instanceof SSEReconnectConfig) {
            return $reconnect;
        }

        if (is_array($reconnect)) {
            return new SSEReconnectConfig(
                enabled: $reconnect['enabled'] ?? true,
                maxAttempts: $reconnect['max_attempts'] ?? 10,
                initialDelay: $reconnect['initial_delay'] ?? 1.0,
                maxDelay: $reconnect['max_delay'] ?? 30.0,
                backoffMultiplier: $reconnect['backoff_multiplier'] ?? 2.0,
                jitter: $reconnect['jitter'] ?? true,
                retryableErrors: $reconnect['retryable_errors'] ?? [
                    'Connection refused',
                    'Connection reset',
                    'Connection timed out',
                    'Could not resolve host',
                    'Resolving timed out',
                    'SSL connection timeout',
                    'Operation timed out',
                    'Network is unreachable',
                ],
                onReconnect: $reconnect['on_reconnect'] ?? null,
                shouldReconnect: $reconnect['should_reconnect'] ?? null,
            );
        }

        return null;
    }

    /**
     * Dispatches the request to the network, applying retry logic if configured.
     *
     * @param  string  $url  The target URL.
     * @param  array<int, mixed>  $curlOptions  cURL options for the request.
     * @param  RetryConfig|null  $retryConfig  Optional retry configuration.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    private function dispatchRequest(string $url, array $curlOptions, ?RetryConfig $retryConfig): PromiseInterface
    {
        if ($retryConfig !== null) {
            return $this->fetchWithRetry($url, $curlOptions, $retryConfig);
        }

        return $this->executeBasicFetch($url, $curlOptions);
    }

    /**
     * Normalizes headers array to the expected format.
     *
     * @param  array<mixed>  $headers  The headers to normalize.
     * @return array<string, array<string>|string> Normalized headers.
     */
    private function normalizeHeaders(array $headers): array
    {
        /** @var array<string, array<string>|string> $normalized */
        $normalized = [];

        foreach ($headers as $key => $value) {
            if (is_string($key)) {
                if (is_string($value)) {
                    $normalized[$key] = $value;
                } elseif (is_array($value)) {
                    $stringValues = array_filter($value, 'is_string');
                    if (count($stringValues) > 0) {
                        $normalized[$key] = array_values($stringValues);
                    }
                }
            }
        }

        return $normalized;
    }

    /**
     * Generates the unique cache key for a given URL.
     *
     * @param  string  $url  The URL to generate a cache key for.
     * @return string The unique cache key.
     */
    private function generateCacheKey(string $url): string
    {
        return 'http_' . sha1($url);
    }

    /**
     * Lazily creates and returns a default PSR-16 cache instance.
     *
     * @return CacheInterface The default cache instance.
     */
    private static function getDefaultCache(): CacheInterface
    {
        if (self::$defaultCache === null) {
            $httpConfigLoader = HttpConfigLoader::getInstance();

            $httpConfig = $httpConfigLoader->get('client', []);

            $cacheDirectory = $httpConfig['cache']['path'] ?? null;

            if ($cacheDirectory === null) {
                $rootPath = $httpConfigLoader->getRootPath();
                $cacheDirectory = $rootPath
                    ? $rootPath . '/storage/framework/cache/data'
                    : sys_get_temp_dir() . '/fiber_async_http_cache';
            }

            if (!is_dir($cacheDirectory)) {
                if (!mkdir($cacheDirectory, 0775, true) && !is_dir($cacheDirectory)) {
                    throw new RuntimeException(sprintf('Cache directory "%s" could not be created', $cacheDirectory));
                }
            }

            $psr6Cache = new FilesystemAdapter('http_client', 0, $cacheDirectory);
            self::$defaultCache = new Psr16Cache($psr6Cache);
        }

        return self::$defaultCache;
    }

    /**
     * Calculates the expiry timestamp based on Cache-Control headers or the default TTL from config.
     *
     * @param  Response  $response  The HTTP response.
     * @param  CacheConfig  $cacheConfig  The cache configuration.
     * @return int The expiry timestamp.
     */
    private function calculateExpiry(Response $response, CacheConfig $cacheConfig): int
    {
        if ($cacheConfig->respectServerHeaders) {
            $header = $response->getHeaderLine('Cache-Control');
            if ($header !== '' && preg_match('/max-age=(\d+)/', $header, $matches) === 1) {
                return time() + (int) $matches[1];
            }
        }

        return time() + $cacheConfig->ttlSeconds;
    }
}
