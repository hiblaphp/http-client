<?php

namespace Hibla\Http;

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
     * @param  int  $ttlSeconds  The Time-To-Live in seconds for this request. This is used as a fallback if the server does not provide `Cache-Control` headers.
     * @param  bool  $respectServerHeaders  If true, the client will prioritize `Cache-Control: max-age` headers from the server over the default TTL.
     * @param  CacheInterface|null  $cache  An optional, custom PSR-16 cache implementation (e.g., Redis, Memcached). If null, the handler's default filesystem cache will be used automatically.
     */
    public function __construct(
        public readonly int $ttlSeconds = 3600,
        public readonly bool $respectServerHeaders = true,
        public readonly ?CacheInterface $cache = null
    ) {}
}
