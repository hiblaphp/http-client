<?php

use Hibla\HttpClient\CacheConfig;
use Hibla\HttpClient\Response;
use Psr\SimpleCache\CacheInterface;

describe('CacheManager', function () {
    test('can reset default cache', function () {
        $cacheManager = createCacheManager();
        
        $config = new CacheConfig(ttlSeconds: 60);
        $response = new Response('test body', 200, []);
        
        $cacheManager->cacheResponse('https://example.com', $response, $config);
        $cached = $cacheManager->getCachedResponse('https://example.com', $config);
        
        expect($cached)->not->toBeNull();
        
        $cacheManager->reset();
        
        $newConfig = new CacheConfig(ttlSeconds: 60);
        $newCached = $cacheManager->getCachedResponse('https://example.com', $newConfig);
        
        expect($newCached)->toBeNull();
    });

    test('can cache and retrieve response', function () {
        $cacheManager = createCacheManager();
        
        $config = new CacheConfig(ttlSeconds: 60);
        $response = new Response('cached body', 200, ['Content-Type' => 'application/json']);
        
        $cacheManager->cacheResponse('https://example.com/api', $response, $config);
        $cached = $cacheManager->getCachedResponse('https://example.com/api', $config);
        
        expect($cached)->not->toBeNull()
            ->and($cached->body())->toBe('cached body')
            ->and($cached->status())->toBe(200)
            ->and($cached->headers())->toBe(['content-type' => 'application/json']); // Changed to lowercase
            
        $cacheManager->reset();
    });

    test('returns null for non-existent cached response', function () {
        $cacheManager = createCacheManager();
        $config = new CacheConfig(ttlSeconds: 60);
        
        $cached = $cacheManager->getCachedResponse('https://example.com/not-cached', $config);
        
        expect($cached)->toBeNull();
        
        $cacheManager->reset();
    });

    test('returns null for expired cached response', function () {
        $cacheManager = createCacheManager();
        $config = new CacheConfig(ttlSeconds: -1);
        $response = new Response('expired body', 200, []);
        
        $cacheManager->cacheResponse('https://example.com/expired', $response, $config);
        
        sleep(1);
        
        $cached = $cacheManager->getCachedResponse('https://example.com/expired', $config);
        
        expect($cached)->toBeNull();
        
        $cacheManager->reset();
    });

    test('uses custom cache instance from config', function () {
        $cacheManager = createCacheManager();
        $customCache = Mockery::mock(CacheInterface::class);
        $customCache->shouldReceive('set')
            ->once()
            ->with('custom_key', Mockery::any(), 60);
        
        $config = new CacheConfig(
            ttlSeconds: 60,
            cache: $customCache,
            cacheKey: 'custom_key'
        );
        
        $response = new Response('test', 200, []);
        $cacheManager->cacheResponse('https://example.com', $response, $config);
    });

    test('uses custom cache key from config', function () {
        $cacheManager = createCacheManager();
        
        $config = new CacheConfig(
            ttlSeconds: 60,
            cacheKey: 'my_custom_key'
        );
        
        $response = new Response('test body', 200, []);
        $cacheManager->cacheResponse('https://example.com', $response, $config);
        
        $cached = $cacheManager->getCachedResponse('https://example.com', $config);
        
        expect($cached)->not->toBeNull()
            ->and($cached->body())->toBe('test body');
            
        $cacheManager->reset();
    });

    test('generates consistent cache keys for same URL', function () {
        $cacheManager = createCacheManager();
        
        $config1 = new CacheConfig(ttlSeconds: 60);
        $config2 = new CacheConfig(ttlSeconds: 60);
        
        $response = new Response('test', 200, []);
        $cacheManager->cacheResponse('https://example.com', $response, $config1);
        
        $cached = $cacheManager->getCachedResponse('https://example.com', $config2);
        
        expect($cached)->not->toBeNull();
        
        $cacheManager->reset();
    });

    test('handles invalid cached data structure gracefully', function () {
        $cacheManager = createCacheManager();
        $customCache = Mockery::mock(CacheInterface::class);
        $customCache->shouldReceive('get')
            ->andReturn('invalid data');
        
        $config = new CacheConfig(
            ttlSeconds: 60,
            cache: $customCache,
            cacheKey: 'test_key'
        );
        
        $cached = $cacheManager->getCachedResponse('https://example.com', $config);
        
        expect($cached)->toBeNull();
    });

    test('handles missing required fields in cached data', function () {
        $cacheManager = createCacheManager();
        $customCache = Mockery::mock(CacheInterface::class);
        $customCache->shouldReceive('get')
            ->andReturn([
                'body' => 'test',
                'status' => 200,
                // missing headers and expires_at
            ]);
        
        $config = new CacheConfig(
            ttlSeconds: 60,
            cache: $customCache,
            cacheKey: 'test_key'
        );
        
        $cached = $cacheManager->getCachedResponse('https://example.com', $config);
        
        expect($cached)->toBeNull();
    });

    test('caches response with correct expiry time', function () {
        $cacheManager = createCacheManager();
        
        $config = new CacheConfig(ttlSeconds: 3600);
        $response = new Response('test', 200, []);
        
        $cacheManager->cacheResponse('https://example.com', $response, $config);
        
        $cached = $cacheManager->getCachedResponse('https://example.com', $config);
        
        expect($cached)->not->toBeNull();
        
        $config2 = new CacheConfig(ttlSeconds: 3600);
        $stillCached = $cacheManager->getCachedResponse('https://example.com', $config2);
        expect($stillCached)->not->toBeNull();
        
        $cacheManager->reset();
    });
});