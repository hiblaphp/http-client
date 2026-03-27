<?php

declare(strict_types=1);

describe('Utilities Integration', function () {
    test('multiple utility managers maintain independence', function () {
        $fileManager = createFileManager();
        $cookieManager = createCookieManager();

        $file = $fileManager->createTempFile('independence_' . uniqid() . '.txt', 'content');
        $cookieManager->addCookie('test', 'value');

        expect(file_exists($file))->toBeTrue()
            ->and($cookieManager->getCookieCount())->toBe(1)
        ;

        $fileManager->cleanup();

        expect(file_exists($file))->toBeFalse()
            ->and($cookieManager->getCookieCount())->toBe(1)
        ;

        $cookieManager->cleanup();

        expect($cookieManager->getCookieCount())->toBe(0);
    });

    test('cleanup methods are idempotent', function () {
        $fileManager = createFileManager();
        $cookieManager = createCookieManager();

        $fileManager->createTempFile('idempotent_' . uniqid() . '.txt');
        $cookieManager->addCookie('test', 'value');

        $fileManager->cleanup();
        $fileManager->cleanup();

        $cookieManager->cleanup();
        $cookieManager->cleanup();

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
            $files[$index] = $manager->createTempFile('concurrent_' . uniqid() . "_{$index}.txt", "content_{$index}");
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
});
