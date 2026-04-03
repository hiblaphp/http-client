<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Hibla\HttpClient\ValueObjects\DownloadProgress;
use Hibla\HttpClient\ValueObjects\UploadProgress;

use function Hibla\await;

describe('Mocked Download and Upload', function () {
    $createTestFiles = function (int $size = 25000) {
        $source = sys_get_temp_dir() . '/test_src_' . bin2hex(random_bytes(4)) . '.txt';
        $dest = sys_get_temp_dir() . '/test_dst_' . bin2hex(random_bytes(4)) . '.txt';

        file_put_contents($source, str_repeat('A', $size));

        return [$source, $dest];
    };

    $cleanup = function (array $files) {
        foreach ($files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    };

    beforeEach(fn () => Http::startTesting());
    afterEach(fn () => Http::stopTesting());

    it('simulates chunked progress during a mocked download', function () use ($createTestFiles, $cleanup) {
        [$sourceFile, $destFile] = $createTestFiles(25000);
        $mockContent = str_repeat('B', 25000);

        Http::mock('GET')
            ->url('https://api.example.com/download')
            ->downloadFile($mockContent, 'downloaded.txt')
            ->register()
        ;

        $progressUpdates = [];

        $result = await(Http::download(
            'https://api.example.com/download',
            $destFile,
            function (DownloadProgress $progress) use (&$progressUpdates) {
                $progressUpdates[] = $progress->percent;
            }
        ));

        expect($result['status'])->toBe(200)
            ->and($result['size'])->toBe(25000)
            ->and($progressUpdates)->toHaveCount(4)
            ->and(end($progressUpdates))->toBe(100.0)
        ;

        expect(file_get_contents($destFile))->toBe($mockContent);

        Http::assertDownloadMade('https://api.example.com/download', $destFile);

        $cleanup([$sourceFile, $destFile]);
    });

    it('simulates chunked progress during a mocked upload', function () use ($createTestFiles, $cleanup) {
        [$sourceFile, $destFile] = $createTestFiles(25000);

        Http::mock('PUT')
            ->url('https://api.example.com/upload')
            ->respondWithStatus(201)
            ->register()
        ;

        $progressUpdates = [];

        $result = await(Http::upload(
            'https://api.example.com/upload',
            $sourceFile,
            function (UploadProgress $progress) use (&$progressUpdates) {
                $progressUpdates[] = $progress->percent;
            }
        ));

        expect($result['status'])->toBe(201)
            ->and($progressUpdates)->toHaveCount(4)
            ->and(end($progressUpdates))->toBe(100.0)
        ;

        Http::assertUploadMade('https://api.example.com/upload', $sourceFile);

        $cleanup([$sourceFile, $destFile]);
    });

    it('gracefully handles empty file progress', function () use ($createTestFiles, $cleanup) {
        [$sourceFile, $destFile] = $createTestFiles(0);

        Http::mock('PUT')
            ->url('https://api.example.com/empty-upload')
            ->respondWithStatus(200)
            ->register()
        ;

        $progressCalled = false;

        await(Http::upload(
            'https://api.example.com/empty-upload',
            $sourceFile,
            function (UploadProgress $progress) use (&$progressCalled) {
                $progressCalled = true;
                expect($progress->total)->toBe(0);
            }
        ));
        expect($progressCalled)->toBeTrue();

        $cleanup([$sourceFile, $destFile]);
    });
});
