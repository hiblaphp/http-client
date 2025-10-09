<?php

namespace Hibla\HttpClient\Handlers;

use function Hibla\async;
use function Hibla\await;

use Hibla\HttpClient\CacheConfig;
use Hibla\HttpClient\Config\HttpConfigLoader;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\RetryConfig;
use Hibla\Promise\Interfaces\PromiseInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Handles HTTP response caching with support for cache validation.
 *
 * This handler implements HTTP caching semantics including ETags,
 * Last-Modified headers, and Cache-Control directives.
 */
class CacheHandler
{
    private static ?CacheInterface $defaultCache = null;
    private RetryHandler $retryHandler;
    private RequestExecutorHandler $requestExecutor;

    public function __construct(?RequestExecutorHandler $requestExecutor = null, ?RetryHandler $retryHandler = null)
    {
        $this->requestExecutor = $requestExecutor ?? new RequestExecutorHandler();
        $this->retryHandler = $retryHandler ?? new RetryHandler();
    }

    /**
     * Executes an HTTP request with caching support.
     *
     * @param string $url The target URL.
     * @param array<int|string, mixed> $curlOptions cURL options.
     * @param CacheConfig $cacheConfig Cache configuration.
     * @param RetryConfig|null $retryConfig Optional retry configuration.
     * @return PromiseInterface<Response>
     */
    public function execute(
        string $url,
        array $curlOptions,
        CacheConfig $cacheConfig,
        ?RetryConfig $retryConfig = null
    ): PromiseInterface {
        // Only cache GET requests
        if (($curlOptions[CURLOPT_CUSTOMREQUEST] ?? 'GET') !== 'GET') {
            return $this->executeRequest($url, $curlOptions, $retryConfig);
        }

        $cache = $cacheConfig->cache ?? self::getDefaultCache();
        $cacheKey = $cacheConfig->cacheKey ?? $this->generateCacheKey($url);

        /** @var PromiseInterface<Response> */
        return async(function () use ($cache, $cacheKey, $url, $curlOptions, $cacheConfig, $retryConfig): Response {
            /** @var array{body: string, status: int, headers: array<string, array<string>|string>, expires_at: int}|null $cachedItem */
            $cachedItem = $cache->get($cacheKey);

            if ($this->isCachedItemValid($cachedItem)) {
                /** @var array{body: string, status: int, headers: array<string, array<string>|string>, expires_at: int} $cachedItem */
                return new Response($cachedItem['body'], $cachedItem['status'], $cachedItem['headers']);
            }

            if (is_array($cachedItem) && $cacheConfig->respectServerHeaders) {
                $curlOptions = $this->addConditionalHeaders($curlOptions, $cachedItem);
            }

            $response = await($this->executeRequest($url, $curlOptions, $retryConfig));

            if ($response->status() === 304 && is_array($cachedItem)) {
                return $this->handleNotModified($response, $cachedItem, $cache, $cacheKey, $cacheConfig);
            }

            if ($response->successful()) {
                $this->cacheResponse($response, $cache, $cacheKey, $cacheConfig);
            }

            return $response;
        });
    }

    /**
     * Executes the HTTP request with optional retry logic.
     *
     * @param string $url The target URL.
     * @param array<int|string, mixed> $curlOptions cURL options.
     * @param RetryConfig|null $retryConfig Optional retry configuration.
     * @return PromiseInterface<Response>
     */
    private function executeRequest(string $url, array $curlOptions, ?RetryConfig $retryConfig): PromiseInterface
    {
        if ($retryConfig !== null) {
            return $this->retryHandler->execute($url, $curlOptions, $retryConfig);
        }

        return $this->requestExecutor->execute($url, $curlOptions);
    }

    /**
     * Checks if a cached item is still valid.
     *
     * @param mixed $cachedItem The cached item to check.
     * @return bool True if valid, false otherwise.
     */
    private function isCachedItemValid($cachedItem): bool
    {
        return is_array($cachedItem)
            && isset($cachedItem['expires_at'], $cachedItem['body'], $cachedItem['status'], $cachedItem['headers'])
            && is_int($cachedItem['expires_at'])
            && time() < $cachedItem['expires_at']
            && is_string($cachedItem['body'])
            && is_int($cachedItem['status'])
            && is_array($cachedItem['headers']);
    }

    /**
     * Adds conditional headers (If-None-Match, If-Modified-Since) to the request.
     *
     * @param array<int|string, mixed> $curlOptions Original cURL options.
     * @param array{headers: array<string, mixed>} $cachedItem Cached item with headers.
     * @return array<int|string, mixed> Updated cURL options.
     */
    private function addConditionalHeaders(array $curlOptions, array $cachedItem): array
    {
        if (! isset($cachedItem['headers']) || ! is_array($cachedItem['headers'])) {
            return $curlOptions;
        }

        /** @var array<string> $httpHeaders */
        $httpHeaders = [];
        if (isset($curlOptions[CURLOPT_HTTPHEADER]) && is_array($curlOptions[CURLOPT_HTTPHEADER])) {
            $httpHeaders = $curlOptions[CURLOPT_HTTPHEADER];
        }

        $cachedHeaders = $cachedItem['headers'];

        if (isset($cachedHeaders['etag'])) {
            $etag = $this->extractHeaderValue($cachedHeaders['etag']);
            if ($etag !== null) {
                $httpHeaders[] = 'If-None-Match: ' . $etag;
            }
        }

        if (isset($cachedHeaders['last-modified'])) {
            $lastModified = $this->extractHeaderValue($cachedHeaders['last-modified']);
            if ($lastModified !== null) {
                $httpHeaders[] = 'If-Modified-Since: ' . $lastModified;
            }
        }

        $curlOptions[CURLOPT_HTTPHEADER] = $httpHeaders;

        return $curlOptions;
    }

    /**
     * Extracts a string value from a header that might be string or array.
     *
     * @param mixed $headerValue The header value.
     * @return string|null The extracted string or null.
     */
    private function extractHeaderValue($headerValue): ?string
    {
        if (is_string($headerValue)) {
            return $headerValue;
        }

        if (is_array($headerValue) && isset($headerValue[0]) && is_string($headerValue[0])) {
            return $headerValue[0];
        }

        return null;
    }

    /**
     * Handles a 304 Not Modified response.
     *
     * @param Response $response The 304 response.
     * @param array{body: string, status: int, headers: array<string, array<string>|string>, expires_at: int} $cachedItem The cached item.
     * @param CacheInterface $cache The cache instance.
     * @param string $cacheKey The cache key.
     * @param CacheConfig $cacheConfig Cache configuration.
     * @return Response The cached response with updated expiry.
     */
    private function handleNotModified(
        Response $response,
        array $cachedItem,
        CacheInterface $cache,
        string $cacheKey,
        CacheConfig $cacheConfig
    ): Response {
        $newExpiry = $this->calculateExpiry($response, $cacheConfig);
        $cachedItem['expires_at'] = $newExpiry;
        $cache->set($cacheKey, $cachedItem, $newExpiry > time() ? $newExpiry - time() : 0);

        return new Response($cachedItem['body'], 200, $cachedItem['headers']);
    }

    /**
     * Caches a successful response.
     *
     * @param Response $response The response to cache.
     * @param CacheInterface $cache The cache instance.
     * @param string $cacheKey The cache key.
     * @param CacheConfig $cacheConfig Cache configuration.
     */
    private function cacheResponse(
        Response $response,
        CacheInterface $cache,
        string $cacheKey,
        CacheConfig $cacheConfig
    ): void {
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

    /**
     * Generates a cache key for the given URL.
     *
     * @param string $url The URL.
     * @return string The cache key.
     */
    private function generateCacheKey(string $url): string
    {
        return 'http_' . sha1($url);
    }

    /**
     * Calculates the expiry timestamp based on response headers or config.
     *
     * @param Response $response The HTTP response.
     * @param CacheConfig $cacheConfig The cache configuration.
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

    /**
     * Gets or creates the default cache instance.
     *
     * @return CacheInterface The default cache.
     */
    public static function getDefaultCache(): CacheInterface
    {
        if (self::$defaultCache === null) {
            $httpConfigLoader = HttpConfigLoader::getInstance();

            /** @var mixed $httpConfig */
            $httpConfig = $httpConfigLoader->get('client', []);

            $cacheDirectory = null;
            if (
                is_array($httpConfig)
                && isset($httpConfig['cache'])
                && is_array($httpConfig['cache'])
                && isset($httpConfig['cache']['path'])
                && is_string($httpConfig['cache']['path'])
            ) {
                $cacheDirectory = $httpConfig['cache']['path'];
            }

            if ($cacheDirectory === null) {
                $rootPath = $httpConfigLoader->getRootPath();
                $cacheDirectory = is_string($rootPath)
                    ? $rootPath . '/storage/cache'
                    : sys_get_temp_dir() . '/hibla_http_cache';
            }

            if (! is_dir($cacheDirectory)) {
                if (! mkdir($cacheDirectory, 0775, true) && ! is_dir($cacheDirectory)) {
                    throw new RuntimeException(sprintf('Cache directory "%s" could not be created', $cacheDirectory));
                }
            }

            $psr6Cache = new FilesystemAdapter('http', 0, $cacheDirectory);
            self::$defaultCache = new Psr16Cache($psr6Cache);
        }

        return self::$defaultCache;
    }
}
