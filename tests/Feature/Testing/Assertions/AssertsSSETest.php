<?php

use Hibla\HttpClient\Testing\Utilities\RecordedRequest;
use PHPUnit\Framework\AssertionFailedError;

describe('AssertsSSE', function () {
    test('assertSSEConnectionMade validates SSE connection', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([
                ['event' => 'message', 'data' => 'test']
            ])
            ->register();

        $handler->sse('https://example.com/events')->await();

        expect(fn() => $handler->assertSSEConnectionMade('https://example.com/events'))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertNoSSEConnections passes when no SSE connections made', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();
        $handler->fetch('https://example.com')->await();

        expect(fn() => $handler->assertNoSSEConnections())
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertNoSSEConnections fails when SSE connection exists', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register();

        $handler->sse('https://example.com/events')->await();

        expect(fn() => $handler->assertNoSSEConnections())
            ->toThrow(AssertionFailedError::class);
    });

    test('assertSSELastEventId validates Last-Event-ID header', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register();

        $handler->fetch('https://example.com/events', [
            'headers' => [
                'Accept' => 'text/event-stream',
                'Last-Event-ID' => '12345'
            ]
        ])->await();

        expect(fn() => $handler->assertSSELastEventId('12345'))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertSSEConnectionAttempts validates connection attempt count', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register();

        $handler->sse('https://example.com/events')->await();
        $handler->sse('https://example.com/events')->await();

        expect(fn() => $handler->assertSSEConnectionAttempts('https://example.com/events', 2))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertSSEConnectionAttemptsAtLeast validates minimum attempts', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register();

        $handler->sse('https://example.com/events')->await();
        $handler->sse('https://example.com/events')->await();
        $handler->sse('https://example.com/events')->await();

        expect(fn() => $handler->assertSSEConnectionAttemptsAtLeast('https://example.com/events', 2))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertSSEConnectionAttemptsAtMost validates maximum attempts', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register();

        $handler->sse('https://example.com/events')->await();

        expect(fn() => $handler->assertSSEConnectionAttemptsAtMost('https://example.com/events', 2))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertSSEReconnectionOccurred validates reconnection with Last-Event-ID', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register();

        $handler->fetch('https://example.com/events', [
            'headers' => [
                'Accept' => 'text/event-stream',
                'Last-Event-ID' => '123'
            ]
        ])->await();

        expect(fn() => $handler->assertSSEReconnectionOccurred('https://example.com/events'))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertSSEConnectionHasHeader validates specific header', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register();

        $handler->fetch('https://example.com/events', [
            'headers' => [
                'Accept' => 'text/event-stream',
                'X-Custom' => 'value'
            ]
        ])->await();

        expect(fn() => $handler->assertSSEConnectionHasHeader('https://example.com/events', 'X-Custom', 'value'))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertSSEConnectionMissingHeader validates header absence', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register();

        $handler->fetch('https://example.com/events', [
            'headers' => ['Accept' => 'text/event-stream']
        ])->await();

        expect(fn() => $handler->assertSSEConnectionMissingHeader('https://example.com/events', 'X-Missing'))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertSSEConnectionsMadeToMultipleUrls validates multiple connections', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events1')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test1']])
            ->register();

        $handler->mock('GET')
            ->url('https://example.com/events2')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test2']])
            ->register();

        $handler->sse('https://example.com/events1')->await();
        $handler->sse('https://example.com/events2')->await();

        expect(fn() => $handler->assertSSEConnectionsMadeToMultipleUrls([
            'https://example.com/events1',
            'https://example.com/events2'
        ]))->not->toThrow(AssertionFailedError::class);
    });

    test('assertSSEConnectionsInOrder validates connection order', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events1')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test1']])
            ->register();

        $handler->mock('GET')
            ->url('https://example.com/events2')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test2']])
            ->register();

        $handler->sse('https://example.com/events1')->await();
        $handler->sse('https://example.com/events2')->await();

        expect(fn() => $handler->assertSSEConnectionsInOrder([
            'https://example.com/events1',
            'https://example.com/events2'
        ]))->not->toThrow(AssertionFailedError::class);
    });

    test('assertSSEConnectionAuthenticated validates authorization header', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register();

        $handler->fetch('https://example.com/events', [
            'headers' => [
                'Accept' => 'text/event-stream',
                'Authorization' => 'Bearer secret-token'
            ]
        ])->await();

        expect(fn() => $handler->assertSSEConnectionAuthenticated('https://example.com/events', 'secret-token'))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertSSEReconnectionProgression validates increasing event IDs', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register();

        $handler->fetch('https://example.com/events', [
            'headers' => [
                'Accept' => 'text/event-stream',
                'Last-Event-ID' => '1'
            ]
        ])->await();

        $handler->fetch('https://example.com/events', [
            'headers' => [
                'Accept' => 'text/event-stream',
                'Last-Event-ID' => '2'
            ]
        ])->await();

        expect(fn() => $handler->assertSSEReconnectionProgression('https://example.com/events'))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertFirstSSEConnectionHasNoLastEventId validates first connection', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register();

        $handler->fetch('https://example.com/events', [
            'headers' => ['Accept' => 'text/event-stream']
        ])->await();

        expect(fn() => $handler->assertFirstSSEConnectionHasNoLastEventId('https://example.com/events'))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertSSEConnectionCount validates exact connection count', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register();

        $handler->sse('https://example.com/events')->await();
        $handler->sse('https://example.com/events')->await();

        expect(fn() => $handler->assertSSEConnectionCount('https://example.com/events', 2))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('getSSEConnectionAttempts returns all attempts', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register();

        $handler->sse('https://example.com/events')->await();
        $handler->sse('https://example.com/events')->await();

        $attempts = $handler->getSSEConnectionAttempts('https://example.com/events');

        expect($attempts)->toHaveCount(2)
            ->and($attempts[0])->toBeInstanceOf(RecordedRequest::class)
            ->and($attempts[1])->toBeInstanceOf(RecordedRequest::class);
    });
});