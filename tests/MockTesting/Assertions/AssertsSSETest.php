<?php

declare(strict_types=1);

use Hibla\HttpClient\HttpClient;
use Hibla\HttpClient\Testing\Utilities\RecordedRequest;
use PHPUnit\Framework\AssertionFailedError;

describe('AssertsSSE', function () {
    test('assertSSEConnectionMade validates SSE connection', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([
                ['event' => 'message', 'data' => 'test'],
            ])
            ->register()
        ;

        (new HttpClient())
            ->setHandler($handler)
            ->sse('https://example.com/events')
            ->connect()
            ->wait()
        ;

        expect(fn () => $handler->assertSSEConnectionMade('https://example.com/events'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertNoSSEConnections passes when no SSE connections made', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->get('https://example.com')
            ->wait()
        ;

        expect(fn () => $handler->assertNoSSEConnections())
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertNoSSEConnections fails when SSE connection exists', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register()
        ;

        (new HttpClient())
            ->setHandler($handler)
            ->sse('https://example.com/events')
            ->connect()
            ->wait()
        ;

        expect(fn () => $handler->assertNoSSEConnections())
            ->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertSSELastEventId validates Last-Event-ID header', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register()
        ;

        (new HttpClient())
            ->setHandler($handler)
            ->withHeader('Last-Event-ID', '12345')
            ->sse('https://example.com/events')
            ->connect()
            ->wait()
        ;

        expect(fn () => $handler->assertSSELastEventId('12345'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertSSEConnectionAttempts validates connection attempt count', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register()
        ;

        $client = (new HttpClient())->setHandler($handler);
        $client->sse('https://example.com/events')->connect()->wait();
        $client->sse('https://example.com/events')->connect()->wait();

        expect(fn () => $handler->assertSSEConnectionAttempts('https://example.com/events', 2))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertSSEConnectionAttemptsAtLeast validates minimum attempts', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register()
        ;

        $client = (new HttpClient())->setHandler($handler);
        $client->sse('https://example.com/events')->connect()->wait();
        $client->sse('https://example.com/events')->connect()->wait();
        $client->sse('https://example.com/events')->connect()->wait();

        expect(fn () => $handler->assertSSEConnectionAttemptsAtLeast('https://example.com/events', 2))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertSSEConnectionAttemptsAtMost validates maximum attempts', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register()
        ;

        (new HttpClient())
            ->setHandler($handler)
            ->sse('https://example.com/events')
            ->connect()
            ->wait()
        ;

        expect(fn () => $handler->assertSSEConnectionAttemptsAtMost('https://example.com/events', 2))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertSSEReconnectionOccurred validates reconnection with Last-Event-ID', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register()
        ;

        (new HttpClient())
            ->setHandler($handler)
            ->withHeader('Last-Event-ID', '123')
            ->sse('https://example.com/events')
            ->connect()
            ->wait()
        ;

        expect(fn () => $handler->assertSSEReconnectionOccurred('https://example.com/events'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertSSEConnectionHasHeader validates specific header', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register()
        ;

        (new HttpClient())
            ->setHandler($handler)
            ->withHeader('X-Custom', 'value')
            ->sse('https://example.com/events')
            ->connect()
            ->wait()
        ;

        expect(fn () => $handler->assertSSEConnectionHasHeader('https://example.com/events', 'X-Custom', 'value'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertSSEConnectionMissingHeader validates header absence', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([['event' => 'message', 'data' => 'test']])
            ->register()
        ;

        (new HttpClient())
            ->setHandler($handler)
            ->sse('https://example.com/events')
            ->connect()
            ->wait()
        ;

        expect(fn () => $handler->assertSSEConnectionMissingHeader('https://example.com/events', 'X-Missing'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertSSEConnectionsMadeToMultipleUrls validates multiple connections', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/events1')->respondWithSSE([])->register();
        $handler->mock('GET')->url('https://example.com/events2')->respondWithSSE([])->register();

        $client = (new HttpClient())->setHandler($handler);
        $client->sse('https://example.com/events1')->connect()->wait();
        $client->sse('https://example.com/events2')->connect()->wait();

        expect(fn () => $handler->assertSSEConnectionsMadeToMultipleUrls([
            'https://example.com/events1',
            'https://example.com/events2',
        ]))->not->toThrow(AssertionFailedError::class);
    });

    test('assertSSEConnectionsInOrder validates connection order', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/events1')->respondWithSSE([])->register();
        $handler->mock('GET')->url('https://example.com/events2')->respondWithSSE([])->register();

        $client = (new HttpClient())->setHandler($handler);
        $client->sse('https://example.com/events1')->connect()->wait();
        $client->sse('https://example.com/events2')->connect()->wait();

        expect(fn () => $handler->assertSSEConnectionsInOrder([
            'https://example.com/events1',
            'https://example.com/events2',
        ]))->not->toThrow(AssertionFailedError::class);
    });

    test('assertSSEConnectionAuthenticated validates authorization header', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([])
            ->register()
        ;

        (new HttpClient())
            ->setHandler($handler)
            ->withToken('secret-token')
            ->sse('https://example.com/events')
            ->connect()
            ->wait()
        ;

        expect(fn () => $handler->assertSSEConnectionAuthenticated('https://example.com/events', 'secret-token'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertSSEReconnectionProgression validates increasing event IDs', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([])
            ->register()
        ;

        $client = (new HttpClient())->setHandler($handler);

        $client->withHeader('Last-Event-ID', '1')
            ->sse('https://example.com/events')
            ->connect()
            ->wait()
        ;

        $client->withHeader('Last-Event-ID', '2')
            ->sse('https://example.com/events')
            ->connect()
            ->wait()
        ;

        expect(fn () => $handler->assertSSEReconnectionProgression('https://example.com/events'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertFirstSSEConnectionHasNoLastEventId validates first connection', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([])
            ->register()
        ;

        (new HttpClient())
            ->setHandler($handler)
            ->sse('https://example.com/events')
            ->connect()
            ->wait()
        ;

        expect(fn () => $handler->assertFirstSSEConnectionHasNoLastEventId('https://example.com/events'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertSSEConnectionCount validates exact connection count', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([])
            ->register()
        ;

        $client = (new HttpClient())->setHandler($handler);
        $client->sse('https://example.com/events')->connect()->wait();
        $client->sse('https://example.com/events')->connect()->wait();

        expect(fn () => $handler->assertSSEConnectionCount('https://example.com/events', 2))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('getSSEConnectionAttempts returns all attempts', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')
            ->url('https://example.com/events')
            ->respondWithHeader('Accept', 'text/event-stream')
            ->respondWithSSE([])
            ->register()
        ;

        $client = (new HttpClient())->setHandler($handler);
        $client->sse('https://example.com/events')->connect()->wait();
        $client->sse('https://example.com/events')->connect()->wait();

        $attempts = $handler->getSSEConnectionAttempts('https://example.com/events');

        expect($attempts)->toHaveCount(2)
            ->and($attempts[0])->toBeInstanceOf(RecordedRequest::class)
            ->and($attempts[1])->toBeInstanceOf(RecordedRequest::class)
        ;
    });
});
