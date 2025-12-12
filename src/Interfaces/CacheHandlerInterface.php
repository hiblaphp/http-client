<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces;

use Hibla\HttpClient\CacheConfig;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\RetryConfig;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Interface for handling HTTP response caching with cache validation support.
 *
 * Implementations should handle HTTP caching semantics including ETags,
 * Last-Modified headers, Cache-Control directives, and 304 Not Modified responses.
 */
interface CacheHandlerInterface
{
    /**
     * Executes an HTTP request with caching support.
     *
     * This method implements intelligent HTTP caching:
     * - For GET requests, checks the cache first
     * - If cached and valid (not expired), returns cached response immediately
     * - If cached but expired with ETag/Last-Modified, sends conditional request
     * - On 304 Not Modified, refreshes cache TTL and returns cached content
     * - On 200 OK, caches new response if successful
     * - For non-GET requests, bypasses cache entirely
     *
     * Cache validation respects HTTP semantics:
     * - Sends If-None-Match header if cached response has ETag
     * - Sends If-Modified-Since header if cached response has Last-Modified
     * - Honors Cache-Control max-age directive when respectServerHeaders is enabled
     *
     * @param string $url The target URL for the HTTP request
     * @param array<int|string, mixed> $options Request configuration options.
     *                                           Implementations may accept various options such as:
     *                                           - HTTP method, headers, body
     *                                           - Timeout and connection settings
     *                                           - Authentication and SSL configuration
     *                                           - Cookie jar for cookie handling
     *                                           - Custom implementation-specific options
     * @param CacheConfig $cacheConfig Cache configuration including:
     *                                  - cache: PSR-16 CacheInterface instance (uses default if null)
     *                                  - ttlSeconds: Time-to-live in seconds for cached responses
     *                                  - cacheKey: Custom cache key (generated from URL if null)
     *                                  - respectServerHeaders: Whether to honor Cache-Control headers
     * @param RetryConfig|null $retryConfig Optional retry configuration for failed requests.
     *                                       If provided, the handler will automatically retry
     *                                       according to the retry policy.
     *
     * @return PromiseInterface<Response> A promise that resolves to a Response.
     *                                     The response may be from cache or freshly fetched.
     *                                     Rejects with NetworkException on network failures
     *                                     or cache-related exceptions on cache errors.
     */
    public function execute(
        string $url,
        array $options,
        CacheConfig $cacheConfig,
        ?RetryConfig $retryConfig = null
    ): PromiseInterface;
}
