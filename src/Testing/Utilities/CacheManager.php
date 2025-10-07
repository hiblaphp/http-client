<?php

namespace Hibla\HttpClient\Testing\Utilities;

use Hibla\HttpClient\CacheConfig;
use Hibla\HttpClient\Response;
use Psr\SimpleCache\CacheInterface;
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

        if (
            is_array($cachedItem) &&
            isset($cachedItem['expires_at'], $cachedItem['body'], $cachedItem['status'], $cachedItem['headers']) &&
            is_int($cachedItem['expires_at']) &&
            time() < $cachedItem['expires_at'] &&
            is_string($cachedItem['body']) &&
            is_int($cachedItem['status']) &&
            is_array($cachedItem['headers'])
        ) {
            /**
             * @var array<string, string|string[]> $headers
             */
            $headers = $cachedItem['headers'];

            return new Response(
                $cachedItem['body'],
                $cachedItem['status'],
                $headers
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
            self::$defaultCache = new Psr16Cache(new ArrayAdapter());
        }

        return self::$defaultCache;
    }

    private function generateCacheKey(string $url): string
    {
        return 'http_cache_' . md5($url);
    }
}
