<?php

use PHPUnit\Framework\AssertionFailedError;

describe('AssertsRequestBody', function () {
    test('assertRequestWithBody validates exact body content', function () {
        $handler = testingHttpHandler();

        $handler->mock('POST')->url('https://example.com')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com', [
            'method' => 'POST',
            'body' => 'test body content',
        ])->await();

        expect(fn () => $handler->assertRequestWithBody('POST', 'https://example.com', 'test body content'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertRequestBodyContains validates body contains string', function () {
        $handler = testingHttpHandler();

        $handler->mock('POST')->url('https://example.com')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com', [
            'method' => 'POST',
            'body' => 'this is test body content',
        ])->await();

        expect(fn () => $handler->assertRequestBodyContains('POST', 'https://example.com', 'test body'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertRequestWithJson validates JSON body', function () {
        $handler = testingHttpHandler();

        $handler->mock('POST')->url('https://example.com')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com', [
            'method' => 'POST',
            'body' => json_encode(['name' => 'John', 'age' => 30]),
            'headers' => ['Content-Type' => 'application/json'],
        ])->await();

        expect(fn () => $handler->assertRequestWithJson('POST', 'https://example.com', [
            'name' => 'John',
            'age' => 30,
        ]))->not->toThrow(AssertionFailedError::class);
    });

    test('assertRequestJsonContains validates partial JSON', function () {
        $handler = testingHttpHandler();

        $handler->mock('POST')->url('https://example.com')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com', [
            'method' => 'POST',
            'body' => json_encode(['name' => 'John', 'age' => 30, 'city' => 'NYC']),
            'headers' => ['Content-Type' => 'application/json'],
        ])->await();

        expect(fn () => $handler->assertRequestJsonContains('POST', 'https://example.com', [
            'name' => 'John',
        ]))->not->toThrow(AssertionFailedError::class);
    });

    test('assertRequestJsonPath validates nested JSON value', function () {
        $handler = testingHttpHandler();

        $handler->mock('POST')->url('https://example.com')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com', [
            'method' => 'POST',
            'body' => json_encode(['user' => ['name' => 'John', 'age' => 30]]),
            'headers' => ['Content-Type' => 'application/json'],
        ])->await();

        expect(fn () => $handler->assertRequestJsonPath('POST', 'https://example.com', 'user.name', 'John'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertRequestWithEmptyBody passes when body is empty', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();
        $handler->fetch('https://example.com')->await();

        expect(fn () => $handler->assertRequestWithEmptyBody('GET', 'https://example.com'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertRequestHasBody validates non-empty body', function () {
        $handler = testingHttpHandler();

        $handler->mock('POST')->url('https://example.com')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com', [
            'method' => 'POST',
            'body' => 'test body content',
        ])->await();

        expect(fn () => $handler->assertRequestHasBody('POST', 'https://example.com'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertRequestIsJson validates JSON request', function () {
        $handler = testingHttpHandler();

        $handler->mock('POST')->url('https://example.com')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com', [
            'method' => 'POST',
            'body' => json_encode(['key' => 'value']),
            'headers' => ['Content-Type' => 'application/json'],
        ])->await();

        expect(fn () => $handler->assertRequestIsJson('POST', 'https://example.com'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertRequestBodyMatches validates body pattern', function () {
        $handler = testingHttpHandler();

        $handler->mock('POST')->url('https://example.com')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com', [
            'method' => 'POST',
            'body' => 'request-id-12345',
        ])->await();

        expect(fn () => $handler->assertRequestBodyMatches('POST', 'https://example.com', '/request-id-\d+/'))
            ->not->toThrow(AssertionFailedError::class);
    });
});
