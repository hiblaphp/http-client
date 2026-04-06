<?php

declare(strict_types=1);

use Hibla\HttpClient\HttpClient;
use Hibla\HttpClient\Testing\Utilities\RecordedRequest;
use PHPUnit\Framework\AssertionFailedError;

afterEach(function () {
    testingHttpHandler()->reset();
});

describe('AssertsUploads', function () {
    test('assertUploadMade validates upload from source to destination', function () {
        $handler = testingHttpHandler();
        $source = $handler->createTempFile('upload_test.txt', 'dummy content');
        
        $handler->mock('PUT')->url('https://example.com/upload')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->upload('https://example.com/upload', $source)
            ->wait();

        expect(fn () => $handler->assertUploadMade('https://example.com/upload', $source))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertUploadMade fails when source does not match', function () {
        $handler = testingHttpHandler();
        $source = $handler->createTempFile('upload_test.txt', 'dummy content');
        
        $handler->mock('PUT')->url('https://example.com/upload')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->upload('https://example.com/upload', $source)
            ->wait();

        expect(fn () => $handler->assertUploadMade('https://example.com/upload', '/wrong/path.txt'))
            ->toThrow(AssertionFailedError::class, 'Expected upload not found');
    });

    test('assertUploadMadeToUrl validates upload to any destination', function () {
        $handler = testingHttpHandler();
        $source = $handler->createTempFile('auto_test.txt', 'dummy content');
        
        $handler->mock('PUT')->url('https://example.com/upload')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->upload('https://example.com/upload', $source)
            ->wait();

        expect(fn () => $handler->assertUploadMadeToUrl('https://example.com/upload'))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertUploadMadeToUrl fails when URL does not match', function () {
        $handler = testingHttpHandler();
        $source = $handler->createTempFile('auto_test.txt', 'dummy content');
        
        $handler->mock('PUT')->url('https://example.com/upload')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->upload('https://example.com/upload', $source)
            ->wait();

        expect(fn () => $handler->assertUploadMadeToUrl('https://example.com/wrong-url'))
            ->toThrow(AssertionFailedError::class, 'Expected upload not found for URL');
    });

    test('assertNoUploadsMade passes when no uploads made', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->get('https://example.com')
            ->wait();

        expect(fn () => $handler->assertNoUploadsMade())
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertNoUploadsMade fails when uploads exist', function () {
        $handler = testingHttpHandler();
        $source = $handler->createTempFile('fail_test.txt', 'dummy content');
        
        $handler->mock('PUT')->url('https://example.com/upload')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->upload('https://example.com/upload', $source)
            ->wait();

        expect(fn () => $handler->assertNoUploadsMade())
            ->toThrow(AssertionFailedError::class, 'Expected no uploads, but at least one was made');
    });

    test('assertUploadCount validates upload count', function () {
        $handler = testingHttpHandler();
        $source1 = $handler->createTempFile('f1.txt', 'content 1');
        $source2 = $handler->createTempFile('f2.txt', 'content 2');
        
        $handler->mock('PUT')->url('https://example.com/upload1')->respondWithStatus(200)->register();
        $handler->mock('PUT')->url('https://example.com/upload2')->respondWithStatus(200)->register();

        $client = (new HttpClient())->setHandler($handler);
        $client->upload('https://example.com/upload1', $source1)->wait();
        $client->upload('https://example.com/upload2', $source2)->wait();

        expect(fn () => $handler->assertUploadCount(2))
            ->not->toThrow(AssertionFailedError::class);
            
        expect(fn () => $handler->assertUploadCount(3))
            ->toThrow(AssertionFailedError::class);
    });

    test('getUploadRequests returns all uploads', function () {
        $handler = testingHttpHandler();
        $source1 = $handler->createTempFile('f1.txt', 'content');
        $source2 = $handler->createTempFile('f2.txt', 'content');
        
        $handler->mock('PUT')->url('https://example.com/upload1')->respondWithStatus(200)->register();
        $handler->mock('POST')->url('https://example.com/upload2')->respondWithStatus(200)->register();

        $client = (new HttpClient())->setHandler($handler);
        $client->upload('https://example.com/upload1', $source1)->wait();
        $client->withMethod('POST')->upload('https://example.com/upload2', $source2)->wait();

        $uploads = $handler->getUploadRequests();

        expect($uploads)->toHaveCount(2)
            ->and($uploads[0])->toBeInstanceOf(RecordedRequest::class)
            ->and($uploads[0]->getUrl())->toBe('https://example.com/upload1')
            ->and($uploads[0]->getMethod())->toBe('PUT')
            
            ->and($uploads[1])->toBeInstanceOf(RecordedRequest::class)
            ->and($uploads[1]->getUrl())->toBe('https://example.com/upload2')
            ->and($uploads[1]->getMethod())->toBe('POST');
    });

    test('getLastUpload returns last upload', function () {
        $handler = testingHttpHandler();
        $source1 = $handler->createTempFile('f1.txt', 'content');
        $source2 = $handler->createTempFile('f2.txt', 'content');
        
        $handler->mock('PUT')->url('https://example.com/upload1')->respondWithStatus(200)->register();
        $handler->mock('PUT')->url('https://example.com/upload2')->respondWithStatus(200)->register();

        $client = (new HttpClient())->setHandler($handler);
        $client->upload('https://example.com/upload1', $source1)->wait();
        
        $handler->mock('GET')->url('https://example.com/api')->respondWithStatus(200)->register();
        $client->get('https://example.com/api')->wait();
        
        $client->upload('https://example.com/upload2', $source2)->wait();

        $lastUpload = $handler->getLastUpload();

        expect($lastUpload)->toBeInstanceOf(RecordedRequest::class)
            ->and($lastUpload->getUrl())->toBe('https://example.com/upload2');
    });
    
    test('getLastUpload returns null when no uploads exist', function () {
        $handler = testingHttpHandler();
        
        $handler->mock('GET')->url('https://example.com/api')->respondWithStatus(200)->register();
        (new HttpClient())->setHandler($handler)->get('https://example.com/api')->wait();

        $lastUpload = $handler->getLastUpload();

        expect($lastUpload)->toBeNull();
    });
});