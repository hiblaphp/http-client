<?php

namespace Hibla\Http\Handlers;

use Psr\SimpleCache\CacheInterface;
use Hibla\Http\Config\HttpConfigLoader;
use Hibla\EventLoop\EventLoop;
use Hibla\Http\CacheConfig;
use Hibla\Http\Exceptions\HttpException;
use Hibla\Http\Interfaces\CookieJarInterface;
use Hibla\Http\Request;
use Hibla\Http\Response;
use Hibla\Http\RetryConfig;
use Hibla\Http\SSE\SSEEvent;
use Hibla\Http\SSE\SSEReconnectConfig;
use Hibla\Http\SSE\SSEResponse;
use Hibla\Http\Stream;
use Hibla\Http\StreamingResponse;
use Hibla\Promise\CancellablePromise;
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
     * Get the underlying streaming handler.
     *
     * @internal This is for internal use by the Request builder.
     */
    public function getStreamingHandler(): StreamingHandler
    {
        return $this->streamingHandler;
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
     * Performs a quick, asynchronous GET request.
     *
     * @param  string  $url  The target URL.
     * @param  array<string, mixed>  $query  Optional query parameters.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    public function get(string $url, array $query = []): PromiseInterface
    {
        return $this->request()->get($url, $query);
    }

    /**
     * Performs a quick, asynchronous POST request with a JSON payload.
     *
     * @param  string  $url  The target URL.
     * @param  array<string, mixed>  $data  Data to be JSON-encoded and sent as the request body.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    public function post(string $url, array $data = []): PromiseInterface
    {
        return $this->request()->post($url, $data);
    }

    /**
     * Performs a quick, asynchronous PUT request.
     *
     * @param  string  $url  The target URL.
     * @param  array<string, mixed>  $data  Data to be JSON-encoded and sent as the request body.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    public function put(string $url, array $data = []): PromiseInterface
    {
        return $this->request()->put($url, $data);
    }

    /**
     * Performs a quick, asynchronous DELETE request.
     *
     * @param  string  $url  The target URL.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    public function delete(string $url): PromiseInterface
    {
        return $this->request()->delete($url);
    }

    /**
     * Streams an HTTP response, processing it in chunks.
     *
     * Ideal for large responses that should not be fully loaded into memory.
     *
     * @param  string  $url  The URL to stream from.
     * @param  array<int|string, mixed>  $options  Advanced cURL or request options.
     * @param  callable(string): void|null  $onChunk  An optional callback to execute for each received data chunk.
     * @return CancellablePromiseInterface<StreamingResponse> A promise that resolves with a StreamingResponse object.
     */
    public function stream(string $url, array $options = [], ?callable $onChunk = null): CancellablePromiseInterface
    {
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        return $this->streamingHandler->streamRequest($url, $curlOptions, $onChunk);
    }

    /**
     * Asynchronously downloads a file from a URL to a specified destination.
     *
     * @param  string  $url  The URL of the file to download.
     * @param  string  $destination  The local path to save the file.
     * @param  array<int|string, mixed>  $options  Advanced cURL or request options.
     * @return CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>}> A promise that resolves with download metadata.
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
     * Creates a new stream from a file path.
     *
     * @param  string  $path  The path to the file.
     * @param  string  $mode  The mode to open the file with (e.g., 'rb', 'w+b').
     * @return Stream A new Stream object wrapping the file resource.
     *
     * @throws RuntimeException if the file cannot be opened.
     */
    public function createStreamFromFile(string $path, string $mode = 'rb'): Stream
    {
        $resource = @fopen($path, $mode);
        if ($resource === false) {
            throw new RuntimeException("Cannot open file: {$path}");
        }

        return new Stream($resource, $path);
    }

    /**
     * Generates the unique cache key for a given URL.
     * This method is the single source of truth for cache key generation,
     * ensuring consistency between caching and invalidation logic.
     *
     * @param  string  $url  The URL to generate a cache key for.
     * @return string The unique cache key.
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
     * The main entry point for sending a request from the Request builder.
     * It intelligently applies caching logic before proceeding to dispatch the request.
     *
     * @param  string  $url  The target URL.
     * @param  array<int, mixed>  $curlOptions  cURL options for the request.
     * @param  CacheConfig|null  $cacheConfig  Optional cache configuration.
     * @param  RetryConfig|null  $retryConfig  Optional retry configuration.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    public function sendRequest(string $url, array $curlOptions, ?CacheConfig $cacheConfig = null, ?RetryConfig $retryConfig = null): PromiseInterface
    {
        if ($cacheConfig === null || ($curlOptions[CURLOPT_CUSTOMREQUEST] ?? 'GET') !== 'GET') {
            return $this->dispatchRequest($url, $curlOptions, $retryConfig);
        }

        $cache = $cacheConfig->cache ?? self::getDefaultCache();
        $cacheKey = self::generateCacheKey($url);

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
     */
    protected function dispatchRequest(string $url, array $curlOptions, ?RetryConfig $retryConfig): PromiseInterface
    {
        if ($retryConfig !== null) {
            return $this->fetchWithRetry($url, $curlOptions, $retryConfig);
        }

        return $this->executeBasicFetch($url, $curlOptions);
    }

    /**
     * A flexible, fetch-like method for making HTTP requests with streaming support.
     * This method delegates to the FetchHandler for implementation.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  An associative array of request options.
     * @return PromiseInterface<Response>|CancellablePromiseInterface<StreamingResponse>|CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>}> A promise that resolves with a Response, StreamingResponse, or download metadata depending on options.
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
     */
    public function fetchWithRetry(string $url, array $options, RetryConfig $retryConfig): PromiseInterface
    {
        return $this->fetchHandler->fetchWithRetry($url, $options, $retryConfig);
    }

    /**
     * Executes basic fetch without advanced features.
     * This method is kept for backward compatibility with existing sendRequest logic.
     *
     * @param  string  $url  The target URL.
     * @param  array<int, mixed>  $curlOptions  cURL options for the request.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
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
     * Set a default cookie jar for all requests.
     */
    public function setCookieJar(CookieJarInterface $cookieJar): void
    {
        $this->defaultCookieJar = $cookieJar;
    }

    /**
     * Get the default cookie jar.
     */
    public function getCookieJar(): ?CookieJarInterface
    {
        return $this->defaultCookieJar;
    }

    /**
     * Process response cookies and update the cookie jar.
     */
    protected function processCookies(Response $response, string $url, ?CookieJarInterface $cookieJar): void
    {
        if ($cookieJar === null) {
            $cookieJar = $this->defaultCookieJar;
        }

        if ($cookieJar !== null) {
            $response->applyCookiesToJar($cookieJar);
        }
    }

    /**
     * Normalizes fetch options from various formats to cURL options.
     * This method delegates to the FetchHandler for implementation.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  The options to normalize.
     * @return array<int, mixed> Normalized cURL options.
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
