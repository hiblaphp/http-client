<?php

use Hibla\HttpClient\Http;
use Hibla\HttpClient\SSE\SSEEvent;

beforeEach(function () {
    Http::startTesting();
});

afterEach(function () {
    Http::stopTesting();
});

describe('Advanced Retry Mocking', function () {
    it('handles a sequence of timeout failures before success', function () {
        Http::mock()
            ->url('/timeout-test')
            ->timeoutUntilAttempt(3)
            ->respondJson(['status' => 'ok'])
            ->register();

        $response = Http::retry(3, 0.01)->get('/timeout-test')->await();

        expect($response->ok())->toBeTrue();
        Http::assertRequestCount(3);
    });

    it('handles a sequence of status code failures before success', function () {
        Http::mock()
            ->url('/status-fail')
            ->statusFailuresUntilAttempt(4, 503) // 3 failures, 4th succeeds
            ->respondJson(['status' => 'recovered'])
            ->register();

        $response = Http::retry(3, 0.01)->get('/status-fail')->await();

        expect($response->ok())->toBeTrue();
        expect($response->json())->toBe(['status' => 'recovered']);
        Http::assertRequestCount(4);
    });
});

describe('Advanced SSE Mocking', function () {
    it('can mock an SSE stream with periodic keepalive events', function () {
        Http::mock()
            ->url('/sse-keepalive')
            ->sseWithKeepalive([
                ['id' => '1', 'data' => 'first'],
                ['id' => '2', 'data' => 'second'],
            ], 1) // 1 keepalive event between data events
            ->register();
        
        $events = [];
        Http::sse('/sse-keepalive', function (SSEEvent $event) use (&$events) {
            $events[] = $event;
        })->await();

        // 1 data, 1 keepalive, 1 data = 3 total events
        expect($events)->toHaveCount(3);
        expect($events[0]->data)->toBe('first');
        expect($events[1]->isKeepAlive())->toBeTrue();
        expect($events[2]->data)->toBe('second');
    });

    it('can mock an SSE stream with a server-sent retry directive', function () {
        Http::mock()
            ->url('/sse-retry-directive')
            ->sseWithRetryDirective(5000, [['data' => 'message']])
            ->register();
            
        $events = [];
        Http::sse('/sse-retry-directive', function (SSEEvent $event) use (&$events) {
            $events[] = $event;
        })->await();

        expect($events)->toHaveCount(2);
        expect($events[0]->retry)->toBe(5000);
        expect($events[1]->data)->toBe('message');
    });
});