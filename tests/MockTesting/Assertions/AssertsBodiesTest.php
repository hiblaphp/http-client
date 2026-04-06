<?php

declare(strict_types=1);

use Hibla\HttpClient\HttpClient;
use PHPUnit\Framework\AssertionFailedError;

describe('AssertsRequestBody', function () {
    test('assertRequestWithBody validates exact body content', function () {
        $handler = testingHttpHandler();
        $handler->mock('POST')->url('https://example.com')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->body('test body content')
            ->send('POST', 'https://example.com')
            ->wait()
        ;

        expect(fn () => $handler->assertRequestWithBody('POST', 'https://example.com', 'test body content'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertRequestBodyContains validates body contains string', function () {
        $handler = testingHttpHandler();
        $handler->mock('POST')->url('https://example.com')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->body('this is test body content')
            ->send('POST', 'https://example.com')
            ->wait()
        ;

        expect(fn () => $handler->assertRequestBodyContains('POST', 'https://example.com', 'test body'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertRequestWithJson validates JSON body', function () {
        $handler = testingHttpHandler();
        $handler->mock('POST')->url('https://example.com')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->withJson(['name' => 'John', 'age' => 30]) // Automatically sets body and Content-Type header
            ->post('https://example.com')
            ->wait()
        ;

        expect(fn () => $handler->assertRequestWithJson('POST', 'https://example.com', [
            'name' => 'John',
            'age' => 30,
        ]))->not->toThrow(AssertionFailedError::class);
    });

    test('assertRequestJsonContains validates partial JSON', function () {
        $handler = testingHttpHandler();
        $handler->mock('POST')->url('https://example.com')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->withJson(['name' => 'John', 'age' => 30, 'city' => 'NYC'])
            ->post('https://example.com')
            ->wait()
        ;

        expect(fn () => $handler->assertRequestJsonContains('POST', 'https://example.com', [
            'name' => 'John',
        ]))->not->toThrow(AssertionFailedError::class);
    });

    test('assertRequestJsonPath validates nested JSON value', function () {
        $handler = testingHttpHandler();
        $handler->mock('POST')->url('https://example.com')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->withJson(['user' => ['name' => 'John', 'age' => 30]])
            ->post('https://example.com')
            ->wait()
        ;

        expect(fn () => $handler->assertRequestJsonPath('POST', 'https://example.com', 'user.name', 'John'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertRequestWithEmptyBody passes when body is empty', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->get('https://example.com')
            ->wait()
        ;

        expect(fn () => $handler->assertRequestWithEmptyBody('GET', 'https://example.com'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertRequestHasBody validates non-empty body', function () {
        $handler = testingHttpHandler();
        $handler->mock('POST')->url('https://example.com')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->body('test body content')
            ->post('https://example.com')
            ->wait()
        ;

        expect(fn () => $handler->assertRequestHasBody('POST', 'https://example.com'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertRequestIsJson validates JSON request', function () {
        $handler = testingHttpHandler();
        $handler->mock('POST')->url('https://example.com')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->withJson(['key' => 'value'])
            ->post('https://example.com')
            ->wait()
        ;

        expect(fn () => $handler->assertRequestIsJson('POST', 'https://example.com'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertRequestBodyMatches validates body pattern', function () {
        $handler = testingHttpHandler();
        $handler->mock('POST')->url('https://example.com')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->body('request-id-12345')
            ->post('https://example.com')
            ->wait()
        ;

        expect(fn () => $handler->assertRequestBodyMatches('POST', 'https://example.com', '/request-id-\d+/'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });
});
