<?php

namespace Hibla\HttpClient;

use Psr\SimpleCache\CacheInterface;

/**
 * A configuration object for defining HTTP request caching behavior.
 *
 * This object is created fluently via the Request::cache() or Request::cacheWith() methods.
 * It allows for simple TTL-based caching or advanced configuration with custom cache pools.
 */
class CacheConfig
{
    /**
     * Initializes a new cache configuration instance.
     *
     * @param  int  $ttlSeconds  The Time-To-Live in seconds for this request.
     * @param  bool  $respectServerHeaders  If true, the client will prioritize `Cache-Control: max-age` headers.
     * @param  CacheInterface|null  $cache  An optional, custom PSR-16 cache implementation.
     * @param  string|null  $cacheKey  Optional custom cache key. If null, generates from URL.
     */
    public function __construct(
        public readonly int $ttlSeconds = 3600,
        public readonly bool $respectServerHeaders = true,
        public readonly ?CacheInterface $cache = null,
        public readonly ?string $cacheKey = null
    ) {
    }
}
