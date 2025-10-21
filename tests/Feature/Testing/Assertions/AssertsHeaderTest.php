<?php

use PHPUnit\Framework\AssertionFailedError;

describe('AssertsHeaders', function () {
    test('assertHeaderSent passes when header exists', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com', [
            'headers' => ['X-Custom' => 'value'],
        ])->await();

        expect(fn () => $handler->assertHeaderSent('X-Custom'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertHeaderSent validates header value', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com', [
            'headers' => ['X-Custom' => 'expected-value'],
        ])->await();

        expect(fn () => $handler->assertHeaderSent('X-Custom', 'expected-value'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertHeaderSent fails when header value mismatches', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com', [
            'headers' => ['X-Custom' => 'actual-value'],
        ])->await();

        expect(fn () => $handler->assertHeaderSent('X-Custom', 'expected-value'))
            ->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertHeaderNotSent passes when header does not exist', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();
        $handler->fetch('https://example.com')->await();

        expect(fn () => $handler->assertHeaderNotSent('X-Missing'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertHeaderNotSent fails when header exists', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com', [
            'headers' => ['X-Custom' => 'value'],
        ])->await();

        expect(fn () => $handler->assertHeaderNotSent('X-Custom'))
            ->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertHeadersSent validates multiple headers', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com', [
            'headers' => [
                'X-Custom-1' => 'value1',
                'X-Custom-2' => 'value2',
            ],
        ])->await();

        expect(fn () => $handler->assertHeadersSent([
            'X-Custom-1' => 'value1',
            'X-Custom-2' => 'value2',
        ]))->not->toThrow(AssertionFailedError::class);
    });

    test('assertHeaderMatches validates header pattern', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com', [
            'headers' => ['X-Request-Id' => 'req-12345'],
        ])->await();

        expect(fn () => $handler->assertHeaderMatches('X-Request-Id', '/^req-\d+$/'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertBearerTokenSent validates authorization header', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com', [
            'headers' => ['Authorization' => 'Bearer secret-token'],
        ])->await();

        expect(fn () => $handler->assertBearerTokenSent('secret-token'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertContentType validates content type header', function () {
        $handler = testingHttpHandler();

        $handler->mock('POST')->url('https://example.com')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com', [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
        ])->await();

        expect(fn () => $handler->assertContentType('application/json'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertAcceptHeader validates accept header', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com', [
            'headers' => ['Accept' => 'application/json'],
        ])->await();

        expect(fn () => $handler->assertAcceptHeader('application/json'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertUserAgent validates user agent header', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com', [
            'headers' => ['User-Agent' => 'CustomAgent/1.0'],
        ])->await();

        expect(fn () => $handler->assertUserAgent('CustomAgent/1.0'))
            ->not->toThrow(AssertionFailedError::class);
    });
});
