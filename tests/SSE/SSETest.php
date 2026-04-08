<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\Promise\Promise;
use Tests\Fixtures\HttpBin;

use function Hibla\await;

describe('Comprehensive SSE: Realistic Retries & Real Network Passthrough', function () {

    beforeEach(function () {
        HttpBin::skipIfUnreachable();
        Http::startTesting()->enablePassthrough();
    });

    afterEach(function () {
        Http::stopTesting();
    });

    describe('Realistic Retry & Reconnection Scenarios', function () {

        it('retries until success after multiple initial connection failures', function () {
            $url = 'https://api.example.com/retry-test';
            $successData = ['id' => 'final-win', 'data' => 'success after 2 fails'];

            Http::mock('GET')
                ->url($url)
                ->sseFailUntilAttempt(3, [$successData], 'Temporary Network Glitch')
                ->register()
            ;

            $reconnectConfig = new SSEReconnectConfig(
                enabled: true,
                maxAttempts: 3,
                initialDelay: 0.01
            );

            $events = [];
            await(
                Http::request()->sse($url)
                    ->withReconnectConfig($reconnectConfig)
                    ->onEvent(function (SSEEvent $event) use (&$events) {
                        $events[] = $event;
                    })
                    ->connect()
            );

            expect($events)->toHaveCount(1);
            expect($events[0]->id)->toBe('final-win');

            Http::assertSSEConnectionAttempts($url, 3);
            Http::assertSSEConnectionMade($url);
        });

        it('handles HTTP 429 Rate Limiting with automatic backoff and retry', function () {
            $url = 'https://api.example.com/rate-limited';

            Http::mock('GET')
                ->url($url)
                ->sseRateLimitedUntilAttempt(2, [['data' => 'allowed now']])
                ->register()
            ;

            $reconnectConfig = new SSEReconnectConfig(enabled: true, initialDelay: 0.01);

            $received = false;
            await(
                Http::request()->sse($url)
                    ->withReconnectConfig($reconnectConfig)
                    ->onEvent(function () use (&$received) {
                        $received = true;
                    })
                    ->connect()
            );

            expect($received)->toBeTrue();
            Http::assertSSEConnectionAttempts($url, 2);
        });

        it('preserves Last-Event-ID state across multiple mid-stream drops', function () {
            $url = 'https://api.example.com/unstable-stream';

            Http::mock('GET')->url($url)
                ->sseDropAfterEvents([['id' => '10', 'data' => 'msg 1']], 'Drop 1')
                ->register()
            ;

            Http::mock('GET')->url($url)
                ->sseExpectLastEventId('10', [['id' => '20', 'data' => 'msg 2']])
                ->retryableFailure('Drop 2')
                ->register()
            ;

            Http::mock('GET')->url($url)
                ->sseExpectLastEventId('20', [['id' => '30', 'data' => 'final msg']])
                ->register()
            ;

            $ids = [];
            $reconnectConfig = new SSEReconnectConfig(enabled: true, maxAttempts: 5, initialDelay: 0.01);

            $completed = new Promise();

            await(
                Http::request()->sse($url)
                    ->withReconnectConfig($reconnectConfig)
                    ->onEvent(function (SSEEvent $event) use (&$ids, $completed) {
                        $ids[] = $event->id;
                        if (count($ids) === 3) {
                            $completed->resolve(true);
                        }
                    })
                    ->connect()
            );

            await($completed);

            expect($ids)->toBe(['10', '20', '30']);

            Http::assertSSEReconnectionProgression($url);
        });
    });

    describe('Real API Passthrough Integration', function () {

        it('connects to real HttpBin while using testing assertions via enablePassthrough', function () {
            $payload = "id: r-101\nevent: custom-type\ndata: real-network-payload\n\n";
            $url = HttpBin::url('/base64/' . base64_encode($payload));

            $capturedEvent = null;
            await(
                Http::request()
                    ->withHeader('X-Test-ID', 'Hibla-Passthrough')
                    ->sse($url)
                    ->onEvent(function (SSEEvent $event) use (&$capturedEvent) {
                        $capturedEvent = $event;
                    })
                    ->connect()
            );

            expect($capturedEvent)->not->toBeNull();
            expect($capturedEvent->id)->toBe('r-101');
            expect($capturedEvent->event)->toBe('custom-type');

            Http::assertSSEConnectionMade($url);
            Http::assertHeaderSent('X-Test-ID', 'Hibla-Passthrough');
        });

        it('establishes an SSE connection and parses simple events', function () {
            $base64Sse = base64_encode("data: hello world\n\n");

            $events = [];
            $response = await(
                Http::request()->sse(HttpBin::url("/base64/{$base64Sse}"))
                    ->onEvent(function (SSEEvent $event) use (&$events) {
                        $events[] = $event;
                    })
                    ->connect()
            );

            expect($response)->toBeInstanceOf(SSEResponse::class);
            expect($response->status())->toBe(200);
            expect($events)->toHaveCount(1);
            expect($events[0]->data)->toBe('hello world');
        });

        it('correctly parses event IDs and types', function () {
            $payload = "id: 123\nevent: update\ndata: version 2.0\n\n";
            $base64Sse = base64_encode($payload);

            $capturedEvent = null;
            await(
                Http::request()->sse(HttpBin::url("/base64/{$base64Sse}"))
                    ->onEvent(function (SSEEvent $event) use (&$capturedEvent) {
                        $capturedEvent = $event;
                    })
                    ->connect()
            );

            expect($capturedEvent->id)->toBe('123');
            expect($capturedEvent->event)->toBe('update');
            expect($capturedEvent->data)->toBe('version 2.0');
        });

        it('updates and persists the Last-Event-ID on the response object', function () {
            $payload = "id: 999\ndata: first\n\nid: 1000\ndata: second\n\n";
            $base64Sse = base64_encode($payload);

            $response = await(
                Http::request()->sse(HttpBin::url("/base64/{$base64Sse}"))
                    ->onEvent(fn () => null)
                    ->connect()
            );

            expect($response->getLastEventId())->toBe('1000');
        });

        it('handles multiple multi-line data blocks', function () {
            $payload = "data: line one\ndata: line two\n\n";
            $base64Sse = base64_encode($payload);

            $capturedData = null;
            await(
                Http::request()->sse(HttpBin::url("/base64/{$base64Sse}"))
                    ->onEvent(function (SSEEvent $event) use (&$capturedData) {
                        $capturedData = $event->data;
                    })
                    ->connect()
            );

            expect($capturedData)->toBe("line one\nline two");
        });

        it('ignores comments in the SSE stream', function () {
            $payload = ": this is a comment\ndata: visible\n\n";
            $base64Sse = base64_encode($payload);

            $events = [];
            await(
                Http::request()->sse(HttpBin::url("/base64/{$base64Sse}"))
                    ->onEvent(function (SSEEvent $event) use (&$events) {
                        $events[] = $event;
                    })
                    ->connect()
            );

            expect($events)->toHaveCount(1);
            expect($events[0]->data)->toBe('visible');
        });

        it('allows filtering by event type inside the onEvent callback', function () {
            $payload = "event: alert\ndata: help\n\nevent: log\ndata: info\n\n";
            $base64Sse = base64_encode($payload);

            $alerts = [];
            await(
                Http::request()->sse(HttpBin::url("/base64/{$base64Sse}"))
                    ->onEvent(function (SSEEvent $event) use (&$alerts) {
                        if ($event->event === 'alert') {
                            $alerts[] = $event;
                        }
                    })
                    ->connect()
            );

            expect($alerts)->toHaveCount(1);
            expect($alerts[0]->data)->toBe('help');
        });
    });

    describe('Advanced Network Simulation', function () {

        it('simulates a poor network with high latency between every 8KB chunk', function () {
            $url = 'https://slow-api.com/heavy-stream';

            Http::mock('GET')
                ->url($url)
                ->sseWithLimitedEvents(2, fn ($i) => ['data' => "chunk {$i}"])
                ->dataStreamTransferLatency(0.2)
                ->register()
            ;

            $eventCount = 0;
            $completed = new Promise();
            $startTime = microtime(true);

            await(
                Http::request()->sse($url)
                ->onEvent(function () use (&$eventCount, $completed) {
                    $eventCount++;
                    if ($eventCount === 2) {
                        $completed->resolve(true);
                    }
                })
                ->connect()
            );

            await($completed);

            $elapsed = microtime(true) - $startTime;
            expect($elapsed)->toBeGreaterThanOrEqual(0.4);
        });
    });
});
