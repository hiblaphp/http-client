<?php

use Hibla\HttpClient\Testing\Utilities\RecordedRequest;
use PHPUnit\Framework\AssertionFailedError;

describe('AssertsStreams', function () {
    test('assertStreamMade validates stream request', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/stream')->respondWithStatus(200)->register();

        $handler->stream('https://example.com/stream')->await();

        expect(fn() => $handler->assertStreamMade('https://example.com/stream'))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertStreamWithCallback validates stream with callback', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/stream')->respondWithStatus(200)->register();

        $handler->stream('https://example.com/stream', [], fn() => null)->await();

        expect(fn() => $handler->assertStreamWithCallback('https://example.com/stream'))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertNoStreamsMade passes when no streams made', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();
        $handler->fetch('https://example.com')->await();

        expect(fn() => $handler->assertNoStreamsMade())
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertStreamCount validates stream count', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/stream1')->respondWithStatus(200)->register();
        $handler->mock('GET')->url('https://example.com/stream2')->respondWithStatus(200)->register();

        $handler->stream('https://example.com/stream1')->await();
        $handler->stream('https://example.com/stream2')->await();

        expect(fn() => $handler->assertStreamCount(2))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('getStreamRequests returns all streams', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/stream')->respondWithStatus(200)->register();
        $handler->stream('https://example.com/stream')->await();

        $streams = $handler->getStreamRequests();

        expect($streams)->toHaveCount(1)
            ->and($streams[0])->toBeInstanceOf(RecordedRequest::class);
    });

    test('getLastStream returns last stream', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/stream1')->respondWithStatus(200)->register();
        $handler->mock('GET')->url('https://example.com/stream2')->respondWithStatus(200)->register();

        $handler->stream('https://example.com/stream1')->await();
        $handler->stream('https://example.com/stream2')->await();

        $lastStream = $handler->getLastStream();

        expect($lastStream)->toBeInstanceOf(RecordedRequest::class)
            ->and($lastStream->getUrl())->toBe('https://example.com/stream2');
    });
});