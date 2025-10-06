<?php

namespace Hibla\Http\Testing\Utilities\Handlers;

use Hibla\Http\CacheConfig;
use Hibla\Http\Response;
use Hibla\Http\Testing\Utilities\CacheManager;
use Hibla\Http\Testing\Utilities\RequestRecorder;

class CacheHandler
{
    private CacheManager $cacheManager;
    private RequestRecorder $requestRecorder;

    public function __construct(CacheManager $cacheManager, RequestRecorder $requestRecorder)
    {
        $this->cacheManager = $cacheManager;
        $this->requestRecorder = $requestRecorder;
    }

    public function tryServeFromCache(string $url, string $method, ?CacheConfig $cacheConfig): bool
    {
        if ($cacheConfig === null || $method !== 'GET') {
            return false;
        }

        $cachedResponse = $this->cacheManager->getCachedResponse($url, $cacheConfig);

        if ($cachedResponse !== null) {
            $this->requestRecorder->recordRequest('GET (FROM CACHE)', $url, []);
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
        if ($cacheConfig !== null && $method === 'GET' && $response->ok()) {
            $this->cacheManager->cacheResponse($url, $response, $cacheConfig);
        }
    }

    public function cacheResponse(string $url, Response $response, ?CacheConfig $cacheConfig): void
    {
        if ($cacheConfig !== null && $response->ok()) {
            $this->cacheManager->cacheResponse($url, $response, $cacheConfig);
        }
    }
}