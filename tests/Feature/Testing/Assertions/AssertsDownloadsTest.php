<?php

declare(strict_types=1);

use Hibla\HttpClient\HttpClient;
use Hibla\HttpClient\Testing\Utilities\RecordedRequest;
use PHPUnit\Framework\AssertionFailedError;

afterEach(function () {
    testingHttpHandler()->reset();
});

describe('AssertsDownloads', function () {
    test('assertDownloadMade validates download to destination', function () {
        $handler = testingHttpHandler();
        $destination = $handler->createTempFile('test.txt');
        $handler->mock('GET')->url('https://example.com/file.txt')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->download('https://example.com/file.txt', $destination)
            ->wait()
        ;

        expect(fn () => $handler->assertDownloadMade('https://example.com/file.txt', $destination))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertDownloadMadeToUrl validates download to any destination', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/file.txt')->respondWithStatus(200)->register();
        $destination = $handler->createTempFile('auto_test.txt');

        (new HttpClient())
            ->setHandler($handler)
            ->download('https://example.com/file.txt', $destination)
            ->wait()
        ;

        expect(fn () => $handler->assertDownloadMadeToUrl('https://example.com/file.txt'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertFileDownloaded validates file was downloaded', function () {
        $handler = testingHttpHandler();
        $destination = $handler->createTempFile('test.txt');
        $handler->mock('GET')->url('https://example.com/file.txt')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->download('https://example.com/file.txt', $destination)
            ->wait()
        ;

        expect(fn () => $handler->assertFileDownloaded($destination))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertNoDownloadsMade passes when no downloads made', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->get('https://example.com')
            ->wait()
        ;

        expect(fn () => $handler->assertNoDownloadsMade())
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertNoDownloadsMade fails when downloads exist', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/file.txt')->respondWithStatus(200)->register();
        $destination = $handler->createTempFile('fail_test.txt');

        (new HttpClient())
            ->setHandler($handler)
            ->download('https://example.com/file.txt', $destination)
            ->wait()
        ;

        expect(fn () => $handler->assertNoDownloadsMade())
            ->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertDownloadCount validates download count', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/file1.txt')->respondWithStatus(200)->register();
        $handler->mock('GET')->url('https://example.com/file2.txt')->respondWithStatus(200)->register();

        $client = (new HttpClient())->setHandler($handler);
        $client->download('https://example.com/file1.txt', $handler->createTempFile('f1.txt'))->wait();
        $client->download('https://example.com/file2.txt', $handler->createTempFile('f2.txt'))->wait();

        expect(fn () => $handler->assertDownloadCount(2))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertDownloadedFileExists validates file exists', function () {
        $handler = testingHttpHandler();
        $destination = $handler->createTempFile('test.txt');
        $handler->mock('GET')->url('https://example.com/file.txt')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->download('https://example.com/file.txt', $destination)
            ->wait()
        ;

        expect(fn () => $handler->assertDownloadedFileExists($destination))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertDownloadedFileContains validates file content', function () {
        $handler = testingHttpHandler();
        $destination = $handler->createTempFile('test.txt');
        $handler->mock('GET')
            ->url('https://example.com/file.txt')
            ->respondWithStatus(200)
            ->respondWith('expected content')
            ->register()
        ;

        (new HttpClient())
            ->setHandler($handler)
            ->download('https://example.com/file.txt', $destination)
            ->wait()
        ;

        expect(fn () => $handler->assertDownloadedFileContains($destination, 'expected content'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertDownloadedFileContainsString validates substring', function () {
        $handler = testingHttpHandler();
        $destination = $handler->createTempFile('test.txt');
        $handler->mock('GET')
            ->url('https://example.com/file.txt')
            ->respondWithStatus(200)
            ->respondWith('this contains expected text')
            ->register()
        ;

        (new HttpClient())
            ->setHandler($handler)
            ->download('https://example.com/file.txt', $destination)
            ->wait()
        ;

        expect(fn () => $handler->assertDownloadedFileContainsString($destination, 'expected'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertDownloadedFileSize validates file size', function () {
        $handler = testingHttpHandler();
        $destination = $handler->createTempFile('test.txt');
        $content = 'test content';
        $handler->mock('GET')
            ->url('https://example.com/file.txt')
            ->respondWithStatus(200)
            ->respondWith($content)
            ->register()
        ;

        (new HttpClient())
            ->setHandler($handler)
            ->download('https://example.com/file.txt', $destination)
            ->wait()
        ;

        expect(fn () => $handler->assertDownloadedFileSize($destination, strlen($content)))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertDownloadedFileSizeBetween validates file size range', function () {
        $handler = testingHttpHandler();
        $destination = $handler->createTempFile('test.txt');
        $handler->mock('GET')
            ->url('https://example.com/file.txt')
            ->respondWithStatus(200)
            ->respondWith('content')
            ->register()
        ;

        (new HttpClient())
            ->setHandler($handler)
            ->download('https://example.com/file.txt', $destination)
            ->wait()
        ;

        expect(fn () => $handler->assertDownloadedFileSizeBetween($destination, 5, 10))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertDownloadWithMethod validates HTTP method', function () {
        $handler = testingHttpHandler();
        $destination = $handler->createTempFile('method_test.txt');
        $handler->mock('POST')->url('https://example.com/file.txt')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->withMethod('POST')
            ->download('https://example.com/file.txt', $destination)
            ->wait()
        ;

        expect(fn () => $handler->assertDownloadWithMethod('https://example.com/file.txt', 'POST'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('getDownloadRequests returns all downloads', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/file1.txt')->respondWithStatus(200)->register();
        $handler->mock('GET')->url('https://example.com/file2.txt')->respondWithStatus(200)->register();

        $client = (new HttpClient())->setHandler($handler);
        $client->download('https://example.com/file1.txt', $handler->createTempFile('1.txt'))->wait();
        $client->download('https://example.com/file2.txt', $handler->createTempFile('2.txt'))->wait();

        $downloads = $handler->getDownloadRequests();

        expect($downloads)->toHaveCount(2)
            ->and($downloads[0])->toBeInstanceOf(RecordedRequest::class)
            ->and($downloads[1])->toBeInstanceOf(RecordedRequest::class)
        ;
    });

    test('getLastDownload returns last download', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/file1.txt')->respondWithStatus(200)->register();
        $handler->mock('GET')->url('https://example.com/file2.txt')->respondWithStatus(200)->register();

        $client = (new HttpClient())->setHandler($handler);
        $client->download('https://example.com/file1.txt', $handler->createTempFile('1.txt'))->wait();
        $client->download('https://example.com/file2.txt', $handler->createTempFile('2.txt'))->wait();

        $lastDownload = $handler->getLastDownload();

        expect($lastDownload)->toBeInstanceOf(RecordedRequest::class)
            ->and($lastDownload->getUrl())->toBe('https://example.com/file2.txt')
        ;
    });
});
