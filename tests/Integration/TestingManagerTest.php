<?php

use Hibla\HttpClient\CacheConfig;
use Hibla\HttpClient\Response;

describe('Utilities Integration', function () {
    test('FileManager and CookieManager can work together', function () {
        $fileManager = createFileManager();
        $cookieManager = createCookieManager();
        
        $cookieFile = $fileManager->createTempFile('cookies_' . uniqid() . '.json', '[]');
        $jar = $cookieManager->createFileCookieJar($cookieFile);
        
        $cookieManager->addCookie('test', 'value', jarName: 'default');
        
        expect(file_exists($cookieFile))->toBeTrue();
        
        $fileManager->cleanup();
        $cookieManager->cleanup();
        
        expect(file_exists($cookieFile))->toBeFalse();
    });

    test('multiple utility managers maintain independence', function () {
        $fileManager = createFileManager();
        $cookieManager = createCookieManager();
        $cacheManager = createCacheManager();
        
        $file = $fileManager->createTempFile('independence_' . uniqid() . '.txt', 'content');
        $cookieManager->addCookie('test', 'value');
        
        expect(file_exists($file))->toBeTrue()
            ->and($cookieManager->getCookieCount())->toBe(1);
        
        $fileManager->cleanup();
        
        expect(file_exists($file))->toBeFalse()
            ->and($cookieManager->getCookieCount())->toBe(1);
        
        $cookieManager->cleanup();
        
        expect($cookieManager->getCookieCount())->toBe(0);
        
        $cacheManager->reset();
    });

    test('cleanup methods are idempotent', function () {
        $fileManager = createFileManager();
        $cookieManager = createCookieManager();
        $cacheManager = createCacheManager();
        
        $fileManager->createTempFile('idempotent_' . uniqid() . '.txt');
        $cookieManager->addCookie('test', 'value');
        
        $fileManager->cleanup();
        $fileManager->cleanup();
        
        $cookieManager->cleanup();
        $cookieManager->cleanup();
        
        $cacheManager->reset();
        $cacheManager->reset();
        
        expect(true)->toBeTrue(); 
    });

    test('FileManager tracks cookie files created by CookieManager', function () {
        $fileManager = createFileManager();
        $cookieManager = createCookieManager(autoManage: false);
        
        $cookieFile = $cookieManager->createTempCookieFile();
        $fileManager->trackFile($cookieFile);
        
        file_put_contents($cookieFile, '[]');
        
        expect(file_exists($cookieFile))->toBeTrue();
        
        $fileManager->cleanup();
        
        expect(file_exists($cookieFile))->toBeFalse();
        
        $cookieManager->cleanup();
    });

    test('utilities handle concurrent operations', function () {
        $managers = [];
        for ($i = 0; $i < 5; $i++) {
            $managers[] = createFileManager();
        }
        
        $files = [];
        foreach ($managers as $index => $manager) {
            // Use unique prefix to avoid conflicts
            $files[$index] = $manager->createTempFile("concurrent_" . uniqid() . "_{$index}.txt", "content_{$index}");
        }
        
        foreach ($files as $file) {
            expect(file_exists($file))->toBeTrue();
        }
        
        foreach ($managers as $manager) {
            $manager->cleanup();
        }
        
        foreach ($files as $file) {
            expect(file_exists($file))->toBeFalse();
        }
    });

    test('CacheManager reset does not affect FileManager', function () {
        $fileManager = createFileManager();
        $cacheManager = createCacheManager();
        
        $file = $fileManager->createTempFile('cache_test_' . uniqid() . '.txt', 'content');
        
        $cacheManager->reset();
        
        expect(file_exists($file))->toBeTrue();
        
        $fileManager->cleanup();
    });

    test('CookieManager cleanup does not affect CacheManager', function () {
        $cookieManager = createCookieManager();
        $cacheManager = createCacheManager();
        
        $cookieManager->addCookie('test', 'value');
        
        $config = new CacheConfig(ttlSeconds: 60);
        $response = new Response('cached', 200, []);
        $cacheManager->cacheResponse('https://example.com', $response, $config);
        
        $cookieManager->cleanup();
        
        $cached = $cacheManager->getCachedResponse('https://example.com', $config);
        expect($cached)->not->toBeNull();
        
        $cacheManager->reset();
    });
});