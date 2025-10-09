<?php

namespace Hibla\HttpClient\Testing\Utilities\Handlers;

use Hibla\HttpClient\CacheConfig;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\Testing\Utilities\CacheManager;

class CacheHandler
{
    private CacheManager $cacheManager;

    public function __construct(CacheManager $cacheManager)
    {
        $this->cacheManager = $cacheManager;
    }

    public function tryServeFromCache(string $url, string $method, ?CacheConfig $cacheConfig): bool
    {
        if ($cacheConfig === null || $method !== 'GET') {
            return false;
        }

        $cachedResponse = $this->cacheManager->getCachedResponse($url, $cacheConfig);

        if ($cachedResponse !== null) {
            return true;
        }

        return false;
    }

    public function getCachedResponse(string $url, ?CacheConfig $cacheConfig): ?Response
    {
        if ($cacheConfig === null) {
            return null;
        }

        $cachedResponse = $this->cacheManager->getCachedResponse($url, $cacheConfig);

        if ($cachedResponse === null) {
            throw new \RuntimeException('Cache indicated response available but returned null');
        }

        return $cachedResponse;
    }

    public function cacheIfNeeded(string $url, Response $response, ?CacheConfig $cacheConfig, string $method): void
    {
        if ($cacheConfig !== null && $method === 'GET' && $response->successful()) {
            $this->cacheManager->cacheResponse($url, $response, $cacheConfig);
        }
    }

    public function cacheResponse(string $url, Response $response, ?CacheConfig $cacheConfig): void
    {
        if ($cacheConfig !== null && $response->successful()) {
            $this->cacheManager->cacheResponse($url, $response, $cacheConfig);
        }
    }
}
