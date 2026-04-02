<?php

declare(strict_types=1);

use Hibla\HttpClient\HttpClient;
use Hibla\HttpClient\Testing\Utilities\RecordedRequest;
use PHPUnit\Framework\AssertionFailedError;

afterEach(function () {
    testingHttpHandler()->reset();
});

describe('AssertsRequests', function () {
    test('assertRequestMade passes when request exists', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://example.com/api')
            ->respondWithStatus(200)
            ->register()
        ;

        (new HttpClient())
            ->setHandler($handler)
            ->get('https://example.com/api')
            ->wait()
        ;

        expect(fn () => $handler->assertRequestMade('GET', 'https://example.com/api'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertRequestMade fails when request does not exist', function () {
        $handler = testingHttpHandler();

        expect(fn () => $handler->assertRequestMade('GET', 'https://example.com/api'))
            ->toThrow(AssertionFailedError::class, 'Expected request not found: GET https://example.com/api')
        ;
    });

    test('assertNoRequestsMade passes when no requests made', function () {
        $handler = testingHttpHandler();

        expect(fn () => $handler->assertNoRequestsMade())
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertNoRequestsMade fails when requests exist', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->get('https://example.com')
            ->wait()
        ;

        expect(fn () => $handler->assertNoRequestsMade())
            ->toThrow(AssertionFailedError::class, 'Expected no requests, but 1 were made')
        ;
    });

    test('assertRequestCount passes with correct count', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')->url('https://example.com/1')->respondWithStatus(200)->register();
        $handler->mock('GET')->url('https://example.com/2')->respondWithStatus(200)->register();

        $client = (new HttpClient())->setHandler($handler);

        $client->get('https://example.com/1')->wait();
        $client->get('https://example.com/2')->wait();

        expect(fn () => $handler->assertRequestCount(2))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertRequestCount fails with incorrect count', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->get('https://example.com')
            ->wait()
        ;

        expect(fn () => $handler->assertRequestCount(2))
            ->toThrow(AssertionFailedError::class, 'Expected 2 requests, but 1 were made')
        ;
    });

    test('getLastRequest returns last request', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')->url('https://example.com/1')->respondWithStatus(200)->register();
        $handler->mock('GET')->url('https://example.com/2')->respondWithStatus(200)->register();

        $client = (new HttpClient())->setHandler($handler);

        $client->get('https://example.com/1')->wait();
        $client->get('https://example.com/2')->wait();

        $lastRequest = $handler->getLastRequest();

        expect($lastRequest)->toBeInstanceOf(RecordedRequest::class)
            ->and($lastRequest->getUrl())->toBe('https://example.com/2')
        ;
    });

    test('getRequest returns request by index', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')->url('https://example.com/1')->respondWithStatus(200)->register();
        $handler->mock('GET')->url('https://example.com/2')->respondWithStatus(200)->register();

        $client = (new HttpClient())->setHandler($handler);

        $client->get('https://example.com/1')->wait();
        $client->get('https://example.com/2')->wait();

        $firstRequest = $handler->getRequest(0);

        expect($firstRequest)->toBeInstanceOf(RecordedRequest::class)
            ->and($firstRequest->getUrl())->toBe('https://example.com/1')
        ;
    });
});
