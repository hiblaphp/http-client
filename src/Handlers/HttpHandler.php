<?php

namespace Hibla\Http\Handlers;

use Psr\SimpleCache\CacheInterface;
use Hibla\Http\Config\HttpConfigLoader;
use Hibla\Http\CacheConfig;
use Hibla\Http\Interfaces\CookieJarInterface;
use Hibla\Http\Request;
use Hibla\Http\Response;
use Hibla\Http\RetryConfig;
use Hibla\Http\SSE\SSEEvent;
use Hibla\Http\SSE\SSEReconnectConfig;
use Hibla\Http\SSE\SSEResponse;
use Hibla\Http\Stream;
use Hibla\Http\StreamingResponse;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

use function Hibla\async;

/**
 * Core handler for creating and dispatching asynchronous HTTP requests.
 *
 * This class acts as the workhorse for the Http Api, translating high-level
 * requests into low-level operations managed by the event loop.
 * 
 * Most methods are marked as @internal and are designed to be overridden
 * by testing implementations like TestingHttpHandler.
 */
class HttpHandler
{
    protected StreamingHandler $streamingHandler;
    protected FetchHandler $fetchHandler;
    protected static ?CacheInterface $defaultCache = null;
    protected ?CookieJarInterface $defaultCookieJar = null;
    protected SSEHandler $sseHandler;

    /**
     * Creates a new HttpHandler instance.
     */
    public function __construct(?StreamingHandler $streamingHandler = null, ?FetchHandler $fetchHandler = null, ?SSEHandler $sseHandler = null)
    {
        $this->streamingHandler = $streamingHandler ?? new StreamingHandler;
        $this->fetchHandler = $fetchHandler ?? new FetchHandler($this->streamingHandler);
        $this->sseHandler = $sseHandler ?? new SSEHandler($this->streamingHandler);
    }

    /**
     * Creates a new fluent HTTP request builder instance.
     *
     * @return Request The request builder.
     */
    public function request(): Request
    {
        return new Request($this);
    }

    /**
     * Creates an SSE (Server-Sent Events) connection with optional reconnection.
     *
     * @param  string  $url  The SSE endpoint URL
     * @param  array<int|string, mixed>  $options  Request options
     * @param  callable(SSEEvent): void|null  $onEvent  Optional callback for each SSE event
     * @param  callable(string): void|null  $onError  Optional callback for connection errors
     * @param  SSEReconnectConfig|null  $reconnectConfig  Optional reconnection configuration
     * @return CancellablePromiseInterface<SSEResponse>
     * 
     * @internal This method is designed for extension by TestingHttpHandler and internal use.
     */
    public function sse(
        string $url,
        array $options = [],
        ?callable $onEvent = null,
        ?callable $onError = null,
        ?SSEReconnectConfig $reconnectConfig = null
    ): CancellablePromiseInterface {
        $curlOptions = $this->normalizeFetchOptions($url, $options, true);

        return $this->sseHandler->connect($url, $curlOptions, $onEvent, $onError, $reconnectConfig);
    }

    /**
     * Streams an HTTP response, processing it in chunks.
     *
     * The $options parameter allows TestingHttpHandler to override this method
     * and provide mocked streaming responses.
     *
     * Ideal for large responses that should not be fully loaded into memory.
     *
     * @param  string  $url  The URL to stream from.
     * @param  array<int|string, mixed>  $options  Request options for internal use and testing extensions.
     * @param  callable(string): void|null  $onChunk  An optional callback to execute for each received data chunk.
     * @return CancellablePromiseInterface<StreamingResponse> A promise that resolves with a StreamingResponse object.
     * 
     * @internal This method is designed for extension by TestingHttpHandler. The $options parameter
     *           allows testing implementations to intercept and mock requests. End users should use
     *           $http->request()->stream() for configuration instead.
     */
    public function stream(string $url, array $options = [], ?callable $onChunk = null): CancellablePromiseInterface
    {
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        return $this->streamingHandler->streamRequest($url, $curlOptions, $onChunk);
    }

    /**
     * Asynchronously downloads a file from a URL to a specified destination.
     *
     * The $options parameter allows TestingHttpHandler to override this method
     * and provide mocked download responses without actual network calls.
     *
     * @param  string  $url  The URL of the file to download.
     * @param  string  $destination  The local path to save the file.
     * @param  array<int|string, mixed>  $options  Request options for internal use and testing extensions.
     * @return CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>}> A promise that resolves with download metadata.
     * 
     * @internal This method is designed for extension by TestingHttpHandler. The $options parameter
     *           allows testing implementations to intercept and mock downloads. End users should use
     *           $http->request()->download() for configuration instead.
     */
    public function download(string $url, string $destination, array $options = []): CancellablePromiseInterface
    {
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        return $this->streamingHandler->downloadFile($url, $destination, $curlOptions);
    }

    /**
     * Creates a new stream from a string.
     *
     * @param  string  $content  The initial content of the stream.
     * @return Stream A new Stream object.
     *
     * @throws RuntimeException If temporary stream creation fails.
     * 
     * @internal This method is designed for extension by TestingHttpHandler for stream mocking.
     */
    public function createStream(string $content = ''): Stream
    {
        $resource = fopen('php://temp', 'w+b');
        if ($resource === false) {
            throw new RuntimeException('Failed to create temporary stream');
        }

        if ($content !== '') {
            fwrite($resource, $content);
            rewind($resource);
        }

        return new Stream($resource);
    }

    /**
     * Generates the unique cache key for a given URL.
     * This method is the single source of truth for cache key generation,
     * ensuring consistency between caching and invalidation logic.
     *
     * @param  string  $url  The URL to generate a cache key for.
     * @return string The unique cache key.
     * 
     * @internal This method is for internal cache key generation and may be used by extensions.
     */
    public static function generateCacheKey(string $url): string
    {
        return 'http_' . sha1($url);
    }

    /**
     * Lazily creates and returns a default PSR-16 cache instance.
     * This enables zero-config caching for the user.
     *
     * @return CacheInterface The default cache instance.
     * 
     * @internal This method is for internal cache initialization. TestingHttpHandler may override
     *           cache behavior through CacheConfig.
     */
    protected static function getDefaultCache(): CacheInterface
    {
        if (self::$defaultCache === null) {
            $httpConfigLoader = HttpConfigLoader::getInstance();

            $httpConfig = $httpConfigLoader->get('client', []);

            $cacheDirectory = $httpConfig['cache']['path'] ?? null;

            if ($cacheDirectory === null) {
                $rootPath = $httpConfigLoader->getRootPath();
                $cacheDirectory = $rootPath
                    ? $rootPath . '/storage/cache'
                    : sys_get_temp_dir() . '/hibla_http_cache';
            }

            if (!is_dir($cacheDirectory)) {
                if (!mkdir($cacheDirectory, 0775, true) && !is_dir($cacheDirectory)) {
                    throw new RuntimeException(sprintf('Cache directory "%s" could not be created', $cacheDirectory));
                }
            }

            $psr6Cache = new FilesystemAdapter('http', 0, $cacheDirectory);
            self::$defaultCache = new Psr16Cache($psr6Cache);
        }

        return self::$defaultCache;
    }

    /**
     * The main entry point for sending a request from the Request builder.
     * It intelligently applies caching logic before proceeding to dispatch the request.
     *
     * TestingHttpHandler overrides this method to intercept requests and return mocked responses.
     *
     * @param  string  $url  The target URL.
     * @param  array<int, mixed>  $curlOptions  cURL options for the request.
     * @param  CacheConfig|null  $cacheConfig  Optional cache configuration.
     * @param  RetryConfig|null  $retryConfig  Optional retry configuration.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     * 
     * @internal This method is the primary extension point for TestingHttpHandler. It is called by
     *           the Request builder and can be overridden to intercept all requests made through
     *           the fluent Request API.
     */
    public function sendRequest(string $url, array $curlOptions, ?CacheConfig $cacheConfig = null, ?RetryConfig $retryConfig = null): PromiseInterface
    {
        if ($cacheConfig === null || ($curlOptions[CURLOPT_CUSTOMREQUEST] ?? 'GET') !== 'GET') {
            return $this->dispatchRequest($url, $curlOptions, $retryConfig);
        }

        $cache = $cacheConfig->cache ?? self::getDefaultCache();
        $cacheKey = $cacheConfig->cacheKey ?? self::generateCacheKey($url);

        /** @var PromiseInterface<Response> */
        return async(function () use ($cache, $cacheKey, $url, $curlOptions, $cacheConfig, $retryConfig): Response {
            /** @var array{body: string, status: int, headers: array<string, array<string>|string>, expires_at: int}|null $cachedItem */
            $cachedItem = $cache->get($cacheKey);

            if ($cachedItem !== null && time() < $cachedItem['expires_at']) {
                return new Response($cachedItem['body'], $cachedItem['status'], $cachedItem['headers']);
            }

            if ($cachedItem !== null && $cacheConfig->respectServerHeaders) {
                /** @var array<string> $httpHeaders */
                $httpHeaders = [];
                if (isset($curlOptions[CURLOPT_HTTPHEADER]) && is_array($curlOptions[CURLOPT_HTTPHEADER])) {
                    $httpHeaders = $curlOptions[CURLOPT_HTTPHEADER];
                }

                if (isset($cachedItem['headers']['etag'])) {
                    $etag = is_array($cachedItem['headers']['etag']) ? $cachedItem['headers']['etag'][0] : $cachedItem['headers']['etag'];
                    $httpHeaders[] = 'If-None-Match: ' . $etag;
                }

                if (isset($cachedItem['headers']['last-modified'])) {
                    $lastModified = is_array($cachedItem['headers']['last-modified']) ? $cachedItem['headers']['last-modified'][0] : $cachedItem['headers']['last-modified'];
                    $httpHeaders[] = 'If-Modified-Since: ' . $lastModified;
                }

                $curlOptions[CURLOPT_HTTPHEADER] = $httpHeaders;
            }

            $response = await($this->dispatchRequest($url, $curlOptions, $retryConfig));

            if ($response->status() === 304 && $cachedItem !== null) {
                $newExpiry = $this->calculateExpiry($response, $cacheConfig);
                $cachedItem['expires_at'] = $newExpiry;
                $cache->set($cacheKey, $cachedItem, $newExpiry > time() ? $newExpiry - time() : 0);

                return new Response($cachedItem['body'], 200, $cachedItem['headers']);
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
     * Dispatches the request to the network, applying retry logic if configured.
     *
     * @param  string  $url  The target URL.
     * @param  array<int, mixed>  $curlOptions  cURL options for the request.
     * @param  RetryConfig|null  $retryConfig  Optional retry configuration.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     * 
     * @internal This method handles the actual request dispatching and is part of the internal
     *           request pipeline. TestingHttpHandler may need to consider this method when
     *           implementing request interception.
     */
    protected function dispatchRequest(string $url, array $curlOptions, ?RetryConfig $retryConfig): PromiseInterface
    {
        if ($retryConfig !== null) {
            return $this->fetchWithRetry($url, $curlOptions, $retryConfig);
        }

        return $this->fetchHandler->executeBasicFetch($url, $curlOptions);
    }

    /**
     * A flexible, fetch-like method for making HTTP requests with streaming support.
     * This method delegates to the FetchHandler for implementation.
     *
     * TestingHttpHandler overrides this to provide comprehensive request mocking.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  An associative array of request options.
     * @return PromiseInterface<Response>|CancellablePromiseInterface<StreamingResponse>|CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>}> A promise that resolves with a Response, StreamingResponse, or download metadata depending on options.
     * 
     * @internal This method is a key extension point for TestingHttpHandler. It handles fetch-style
     *           requests and can return different response types based on options (streaming, downloads, etc.).
     */
    public function fetch(string $url, array $options = []): PromiseInterface|CancellablePromiseInterface
    {
        return $this->fetchHandler->fetch($url, $options);
    }

    /**
     * Sends a request with automatic retry logic on failure.
     * This method delegates to the FetchHandler for implementation.
     *
     * @param  string  $url  The target URL.
     * @param  array<int, mixed>  $options  An array of cURL options.
     * @param  RetryConfig  $retryConfig  Configuration object for retry behavior.
     * @return PromiseInterface<Response> A promise that resolves with a Response object or rejects with an HttpException on final failure.
     * 
     * @internal This method implements retry logic and is used internally by the request pipeline.
     *           TestingHttpHandler may want to control retry behavior in tests.
     */
    protected function fetchWithRetry(string $url, array $options, RetryConfig $retryConfig): PromiseInterface
    {
        return $this->fetchHandler->fetchWithRetry($url, $options, $retryConfig);
    }

    /**
     * Get the default cookie jar.
     * 
     * @internal This method is for internal cookie jar access and may be used by extensions.
     */
    public function getCookieJar(): ?CookieJarInterface
    {
        return $this->defaultCookieJar;
    }

    /**
     * Normalizes fetch options from various formats to cURL options.
     * This method delegates to the FetchHandler for implementation.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  The options to normalize.
     * @param  bool  $ensureSSEHeaders  Whether to ensure SSE-specific headers are set.
     * @return array<int, mixed> Normalized cURL options.
     * 
     * @internal This method converts user-friendly options to cURL options. TestingHttpHandler
     *           may use this to understand request configuration before mocking.
     */
    protected function normalizeFetchOptions(string $url, array $options, bool $ensureSSEHeaders = false): array
    {
        return $this->fetchHandler->normalizeFetchOptions($url, $options, $ensureSSEHeaders);
    }

    /**
     * Normalizes headers array to the expected format.
     *
     * @param  array<mixed>  $headers  The headers to normalize.
     * @return array<string, array<string>|string> Normalized headers.
     * 
     * @internal This method ensures headers are in a consistent format throughout the system.
     *           Used by both production and testing implementations.
     */
    protected function normalizeHeaders(array $headers): array
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
     * Calculates the expiry timestamp based on Cache-Control headers or the default TTL from config.
     *
     * @param  Response  $response  The HTTP response.
     * @param  CacheConfig  $cacheConfig  The cache configuration.
     * @return int The expiry timestamp.
     * 
     * @internal This method implements HTTP caching logic and may be used by extensions
     *           that need to respect cache headers.
     */
    protected function calculateExpiry(Response $response, CacheConfig $cacheConfig): int
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
