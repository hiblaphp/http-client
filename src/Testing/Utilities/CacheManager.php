<?php

namespace Hibla\Http\Testing\Utilities;

use Psr\SimpleCache\CacheInterface;
use Hibla\Http\CacheConfig;
use Hibla\Http\Response;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class CacheManager
{
    private static ?CacheInterface $defaultCache = null;

    public function getCachedResponse(string $url, CacheConfig $cacheConfig): ?Response
    {
        $cache = $cacheConfig->cache ?? $this->getDefaultCache();
        $cacheKey = $this->generateCacheKey($url);
        $cachedItem = $cache->get($cacheKey);

        if ($cachedItem !== null && is_array($cachedItem) && time() < ($cachedItem['expires_at'] ?? 0)) {
            return new Response(
                $cachedItem['body'],
                $cachedItem['status'],
                $cachedItem['headers']
            );
        }

        return null;
    }

    public function cacheResponse(string $url, Response $response, CacheConfig $cacheConfig): void
    {
        $cache = $cacheConfig->cache ?? $this->getDefaultCache();
        $cacheKey = $this->generateCacheKey($url);
        $expiry = time() + $cacheConfig->ttlSeconds;
        $cache->set($cacheKey, [
            'body' => $response->body(),
            'status' => $response->status(),
            'headers' => $response->headers(),
            'expires_at' => $expiry,
        ], $cacheConfig->ttlSeconds);
    }

    private function getDefaultCache(): CacheInterface
    {
        if (self::$defaultCache === null) {
            self::$defaultCache = new Psr16Cache(new ArrayAdapter);
        }

        return self::$defaultCache;
    }

    private function generateCacheKey(string $url): string
    {
        return 'http_cache_'.md5($url);
    }
}
