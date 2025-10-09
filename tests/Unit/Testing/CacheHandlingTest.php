<?php

use Hibla\HttpClient\Testing\Utilities\Handlers\CacheHandler;
use Hibla\HttpClient\Testing\Utilities\CacheManager;
use Hibla\HttpClient\CacheConfig;
use Hibla\HttpClient\Response;

describe('CacheHandler', function () {
    
    describe('tryServeFromCache', function () {
        it('returns false when cache config is null', function () {
            $cacheManager = Mockery::mock(CacheManager::class);
            $handler = new CacheHandler($cacheManager);

            expect($handler->tryServeFromCache('https://example.com', 'GET', null))->toBeFalse();
        });

        it('returns false for non-GET requests', function () {
            $cacheManager = Mockery::mock(CacheManager::class);
            $handler = new CacheHandler($cacheManager);
            $cacheConfig = Mockery::mock(CacheConfig::class);

            expect($handler->tryServeFromCache('https://example.com', 'POST', $cacheConfig))->toBeFalse();
        });

        it('returns true when cached response exists', function () {
            $cacheManager = Mockery::mock(CacheManager::class);
            $cacheConfig = Mockery::mock(CacheConfig::class);
            $response = Mockery::mock(Response::class);
            
            $cacheManager->shouldReceive('getCachedResponse')->andReturn($response);
            
            $handler = new CacheHandler($cacheManager);

            expect($handler->tryServeFromCache('https://example.com', 'GET', $cacheConfig))->toBeTrue();
        });

        it('returns false when no cached response exists', function () {
            $cacheManager = Mockery::mock(CacheManager::class);
            $cacheConfig = Mockery::mock(CacheConfig::class);
            
            $cacheManager->shouldReceive('getCachedResponse')->andReturn(null);
            
            $handler = new CacheHandler($cacheManager);

            expect($handler->tryServeFromCache('https://example.com', 'GET', $cacheConfig))->toBeFalse();
        });
    });

    describe('getCachedResponse', function () {
        it('returns null when cache config is null', function () {
            $cacheManager = Mockery::mock(CacheManager::class);
            $handler = new CacheHandler($cacheManager);

            expect($handler->getCachedResponse('https://example.com', null))->toBeNull();
        });

        it('returns cached response when available', function () {
            $cacheManager = Mockery::mock(CacheManager::class);
            $cacheConfig = Mockery::mock(CacheConfig::class);
            $response = Mockery::mock(Response::class);
            
            $cacheManager->shouldReceive('getCachedResponse')->andReturn($response);
            
            $handler = new CacheHandler($cacheManager);

            expect($handler->getCachedResponse('https://example.com', $cacheConfig))->toBe($response);
        });

        it('throws exception when cache indicates availability but returns null', function () {
            $cacheManager = Mockery::mock(CacheManager::class);
            $cacheConfig = Mockery::mock(CacheConfig::class);
            
            $cacheManager->shouldReceive('getCachedResponse')->andReturn(null);
            
            $handler = new CacheHandler($cacheManager);

            expect(fn() => $handler->getCachedResponse('https://example.com', $cacheConfig))
                ->toThrow(\RuntimeException::class, 'Cache indicated response available but returned null');
        });
    });

    describe('cacheIfNeeded', function () {
        it('caches GET request with ok response', function () {
            $cacheManager = Mockery::mock(CacheManager::class);
            $cacheConfig = Mockery::mock(CacheConfig::class);
            $response = Mockery::mock(Response::class);
            
            $response->shouldReceive('ok')->andReturn(true);
            $cacheManager->shouldReceive('cacheResponse')->once();
            
            $handler = new CacheHandler($cacheManager);
            $handler->cacheIfNeeded('https://example.com', $response, $cacheConfig, 'GET');
        });

        it('does not cache when config is null', function () {
            $cacheManager = Mockery::mock(CacheManager::class);
            $response = Mockery::mock(Response::class);
            
            $cacheManager->shouldReceive('cacheResponse')->never();
            
            $handler = new CacheHandler($cacheManager);
            $handler->cacheIfNeeded('https://example.com', $response, null, 'GET');
        });

        it('does not cache POST requests', function () {
            $cacheManager = Mockery::mock(CacheManager::class);
            $cacheConfig = Mockery::mock(CacheConfig::class);
            $response = Mockery::mock(Response::class);
            
            $cacheManager->shouldReceive('cacheResponse')->never();
            
            $handler = new CacheHandler($cacheManager);
            $handler->cacheIfNeeded('https://example.com', $response, $cacheConfig, 'POST');
        });

        it('does not cache failed responses', function () {
            $cacheManager = Mockery::mock(CacheManager::class);
            $cacheConfig = Mockery::mock(CacheConfig::class);
            $response = Mockery::mock(Response::class);
            
            $response->shouldReceive('ok')->andReturn(false);
            $cacheManager->shouldReceive('cacheResponse')->never();
            
            $handler = new CacheHandler($cacheManager);
            $handler->cacheIfNeeded('https://example.com', $response, $cacheConfig, 'GET');
        });
    });

    describe('cacheResponse', function () {
        it('caches ok response with config', function () {
            $cacheManager = Mockery::mock(CacheManager::class);
            $cacheConfig = Mockery::mock(CacheConfig::class);
            $response = Mockery::mock(Response::class);
            
            $response->shouldReceive('ok')->andReturn(true);
            $cacheManager->shouldReceive('cacheResponse')->once();
            
            $handler = new CacheHandler($cacheManager);
            $handler->cacheResponse('https://example.com', $response, $cacheConfig);
        });

        it('does not cache when config is null', function () {
            $cacheManager = Mockery::mock(CacheManager::class);
            $response = Mockery::mock(Response::class);
            
            $cacheManager->shouldReceive('cacheResponse')->never();
            
            $handler = new CacheHandler($cacheManager);
            $handler->cacheResponse('https://example.com', $response, null);
        });

        it('does not cache failed responses', function () {
            $cacheManager = Mockery::mock(CacheManager::class);
            $cacheConfig = Mockery::mock(CacheConfig::class);
            $response = Mockery::mock(Response::class);
            
            $response->shouldReceive('ok')->andReturn(false);
            $cacheManager->shouldReceive('cacheResponse')->never();
            
            $handler = new CacheHandler($cacheManager);
            $handler->cacheResponse('https://example.com', $response, $cacheConfig);
        });
    });

    afterEach(function () {
        Mockery::close();
    });
});