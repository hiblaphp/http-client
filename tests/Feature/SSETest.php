<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;

beforeEach(function () {
    Http::startTesting();
});

afterEach(function () {
    Http::stopTesting();
});

describe('Server-Sent Events Features', function () {
    it('attempts to reconnect after a connection failure', function () {
        Http::mock()
            ->url('/sse-stream')
            ->sseFailUntilAttempt(2, [
                ['event' => 'reconnected', 'data' => 'hello again', 'id' => '2'],
            ])
            ->register()
        ;

        $events = [];

        Http::request()
            ->sse('/sse-stream')
            ->onEvent(function (SSEEvent $event) use (&$events) {
                $events[] = $event;
            })
            ->reconnectWith(new SSEReconnectConfig(
                maxAttempts: 2,
                initialDelay: 0.01,
            ))
            ->connect()
            ->wait()
        ;

        Http::assertSSEConnectionAttempts('/sse-stream', 2);
        expect($events)->toHaveCount(1);
        expect($events[0]->event)->toBe('reconnected');
    });

    it('does not send Last-Event-ID on reconnect if the first connection failed immediately', function () {
        Http::mock()
            ->url('/sse-reconnect')
            ->sseFailWithSequence([
                ['error' => 'Connection lost', 'retryable' => true],
            ])
            ->respondWithSSE([['id' => 'event-2', 'data' => 'reconnected successfully']])
            ->register()
        ;

        $events = [];

        Http::request()
            ->sse('/sse-reconnect')
            ->onEvent(function (SSEEvent $event) use (&$events) {
                $events[] = $event;
            })
            ->reconnectWith(new SSEReconnectConfig(
                maxAttempts: 2,
                initialDelay: 0.01,
            ))
            ->connect()
            ->wait()
        ;

        Http::assertSSEConnectionAttempts('/sse-reconnect', 2);

        $lastRequest = Http::getLastRequest();
        expect($lastRequest->hasHeader('Last-Event-ID'))->toBeFalse();

        expect($events)->toHaveCount(1);
        expect($events[0]->id)->toBe('event-2');
    });
});
