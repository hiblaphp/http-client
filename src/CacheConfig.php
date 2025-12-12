<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

use Psr\SimpleCache\CacheInterface;

final readonly class CacheConfig
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
        public int $ttlSeconds = 3600,
        public bool $respectServerHeaders = true,
        public ?CacheInterface $cache = null,
        public ?string $cacheKey = null
    ) {
    }
}
