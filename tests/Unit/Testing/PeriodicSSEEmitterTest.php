<?php

use Hibla\EventLoop\EventLoop;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\Factories\SSE\PeriodicSSEEmitter;
use Hibla\Promise\CancellablePromise;

beforeEach(function () {
    EventLoop::reset();
    $this->emitter = new PeriodicSSEEmitter();
    $this->promise = new CancellablePromise();
    $this->mock = Mockery::mock(MockedRequest::class);
});

afterEach(function () {
    EventLoop::reset();
    Mockery::close();
});

describe('PeriodicSSEEmitter', function () {

    it('throws exception when SSE config is missing', function () {
        $this->mock->shouldReceive('getSSEStreamConfig')->andReturn(null);

        $timerId = null;

        expect(fn() => $this->emitter->emit(
            $this->promise,
            $this->mock,
            null,
            null,
            $timerId
        ))->toThrow(RuntimeException::class, 'SSE stream config is required');
    });

    it('resolves promise with SSEResponse', function () {
        $this->mock->shouldReceive('getSSEStreamConfig')->andReturn([
            'type' => 'periodic',
            'events' => []
        ]);

        $this->mock->shouldReceive('getStatusCode')->andReturn(200);
        $this->mock->shouldReceive('getHeaders')->andReturn([]);

        $timerId = null;
        $resolved = false;
        $response = null;

        $this->promise->then(function ($res) use (&$resolved, &$response) {
            $resolved = true;
            $response = $res;
        });

        $this->emitter->emit($this->promise, $this->mock, null, null, $timerId);

        $loop = EventLoop::getInstance();
        $loop->nextTick(function () use ($loop) {
            $loop->stop();
        });
        $loop->run();

        expect($resolved)->toBeTrue();
        expect($response)->toBeInstanceOf(SSEResponse::class);
    });

    describe('Finite Event Stream', function () {

        it('emits finite events with default interval', function () {
            $events = [
                ['data' => 'event1', 'event' => 'test'],
                ['data' => 'event2', 'event' => 'test'],
            ];

            $this->mock->shouldReceive('getSSEStreamConfig')->andReturn([
                'type' => 'periodic',
                'events' => $events,
                'interval' => 0.01,
            ]);
            $this->mock->shouldReceive('getStatusCode')->andReturn(200);
            $this->mock->shouldReceive('getHeaders')->andReturn([]);

            $receivedEvents = [];
            $onEvent = function ($event) use (&$receivedEvents) {
                $receivedEvents[] = $event;
            };

            $timerId = null;
            $this->emitter->emit($this->promise, $this->mock, $onEvent, null, $timerId);

            expect($timerId)->toBeString();

            // Run event loop in background fiber
            $loop = EventLoop::getInstance();
            $stopTimer = $loop->addTimer(0.5, function () use ($loop) {
                $loop->stop();
            });

            $loop->run();

            expect($receivedEvents)->toHaveCount(2);
        });

        it('applies custom interval', function () {
            $this->mock->shouldReceive('getSSEStreamConfig')->andReturn([
                'events' => [['data' => 'test']],
                'interval' => 0.05,
            ]);
            $this->mock->shouldReceive('getStatusCode')->andReturn(200);
            $this->mock->shouldReceive('getHeaders')->andReturn([]);

            $timerId = null;
            $startTime = microtime(true);
            $eventTime = null;

            $onEvent = function () use (&$eventTime) {
                $eventTime = microtime(true);
            };

            $this->emitter->emit($this->promise, $this->mock, $onEvent, null, $timerId);

            $loop = EventLoop::getInstance();
            $loop->addTimer(0.5, function () use ($loop) {
                $loop->stop();
            });

            $loop->run();

            $elapsed = $eventTime - $startTime;
            expect($elapsed)->toBeGreaterThanOrEqual(0.04);
        });

        it('uses default error message when not provided', function () {
            $this->mock->shouldReceive('getSSEStreamConfig')->andReturn([
                'events' => [],
                'interval' => 0.01,
                'auto_close' => true,
            ]);
            $this->mock->shouldReceive('getStatusCode')->andReturn(200);
            $this->mock->shouldReceive('getHeaders')->andReturn([]);
            $this->mock->shouldReceive('shouldFail')->andReturn(true);
            $this->mock->shouldReceive('getError')->andReturn(null);

            $errorReceived = null;
            $onError = function ($error) use (&$errorReceived) {
                $errorReceived = $error;
            };

            $timerId = null;
            $this->emitter->emit($this->promise, $this->mock, null, $onError, $timerId);

            $loop = EventLoop::getInstance();
            $loop->addTimer(0.2, function () use ($loop) {
                $loop->stop();
            });

            $loop->run();

            expect($errorReceived)->toBe('Connection closed');
        });
    });

    describe('Infinite Event Stream', function () {

        it('emits infinite events using generator', function () {
            $generator = function (int $index) {
                return ['data' => "event{$index}", 'event' => 'test'];
            };

            $this->mock->shouldReceive('getSSEStreamConfig')->andReturn([
                'type' => 'infinite',
                'event_generator' => $generator,
                'interval' => 0.01,
                'max_events' => 3,
            ]);
            $this->mock->shouldReceive('getStatusCode')->andReturn(200);
            $this->mock->shouldReceive('getHeaders')->andReturn([]);

            $receivedEvents = [];
            $onEvent = function ($event) use (&$receivedEvents) {
                $receivedEvents[] = $event;
            };

            $timerId = null;
            $this->emitter->emit($this->promise, $this->mock, $onEvent, null, $timerId);

            $loop = EventLoop::getInstance();
            $loop->addTimer(0.5, function () use ($loop) {
                $loop->stop();
            });

            $loop->run();

            expect($receivedEvents)->toHaveCount(3);
        });

        it('stops after max_events reached', function () {
            $generator = function (int $index) {
                return ['data' => "event{$index}"];
            };

            $this->mock->shouldReceive('getSSEStreamConfig')->andReturn([
                'type' => 'infinite',
                'event_generator' => $generator,
                'interval' => 0.01,
                'max_events' => 2,
            ]);
            $this->mock->shouldReceive('getStatusCode')->andReturn(200);
            $this->mock->shouldReceive('getHeaders')->andReturn([]);

            $eventCount = 0;
            $onEvent = function () use (&$eventCount) {
                $eventCount++;
            };

            $timerId = null;
            $this->emitter->emit($this->promise, $this->mock, $onEvent, null, $timerId);

            $loop = EventLoop::getInstance();
            $loop->addTimer(0.3, function () use ($loop) {
                $loop->stop();
            });

            $loop->run();

            expect($eventCount)->toBe(2);
        });

        it('does not require max_events', function () {
            $generator = function (int $index) {
                return ['data' => "event{$index}"];
            };

            $this->mock->shouldReceive('getSSEStreamConfig')->andReturn([
                'type' => 'infinite',
                'event_generator' => $generator,
                'interval' => 0.01,
            ]);
            $this->mock->shouldReceive('getStatusCode')->andReturn(200);
            $this->mock->shouldReceive('getHeaders')->andReturn([]);

            $eventCount = 0;
            $onEvent = function () use (&$eventCount) {
                $eventCount++;
            };

            $timerId = null;
            $this->emitter->emit($this->promise, $this->mock, $onEvent, null, $timerId);

            expect($timerId)->toBeString();

            $loop = EventLoop::getInstance();

            // Let it run for a bit, then stop
            $loop->addTimer(0.1, function () use ($loop, $timerId) {
                EventLoop::getInstance()->cancelTimer($timerId);
                $loop->stop();
            });

            $loop->run();

            // Should have emitted some events
            expect($eventCount)->toBeGreaterThan(0);
        });

        it('ignores non-callable event_generator', function () {
            $this->mock->shouldReceive('getSSEStreamConfig')->andReturn([
                'type' => 'infinite',
                'event_generator' => 'not-callable',
                'interval' => 0.01,
            ]);
            $this->mock->shouldReceive('getStatusCode')->andReturn(200);
            $this->mock->shouldReceive('getHeaders')->andReturn([]);
            $this->mock->shouldReceive('shouldFail')->andReturn(false);

            $eventCount = 0;
            $onEvent = function () use (&$eventCount) {
                $eventCount++;
            };

            $timerId = null;
            $this->emitter->emit($this->promise, $this->mock, $onEvent, null, $timerId);

            expect($timerId)->toBeString();

            $loop = EventLoop::getInstance();
            $loop->addTimer(0.1, function () use ($loop) {
                $loop->stop();
            });

            $loop->run();

            expect($eventCount)->toBe(0);
        });
    });

    describe('Jitter Application', function () {

        it('applies jitter to event timing', function () {
            $this->mock->shouldReceive('getSSEStreamConfig')->andReturn([
                'events' => [['data' => 'test1'], ['data' => 'test2']],
                'interval' => 0.1,
                'jitter' => 0.5, 
            ]);
            $this->mock->shouldReceive('getStatusCode')->andReturn(200);
            $this->mock->shouldReceive('getHeaders')->andReturn([]);

            $eventTimes = [];
            $onEvent = function () use (&$eventTimes) {
                $eventTimes[] = microtime(true);
            };

            $timerId = null;
            $this->emitter->emit($this->promise, $this->mock, $onEvent, null, $timerId);

            $loop = EventLoop::getInstance();
            $loop->addTimer(1.0, function () use ($loop) {
                $loop->stop();
            });

            $loop->run();

            expect($eventTimes)->toHaveCount(2);
        });

        it('handles zero jitter', function () {
            $this->mock->shouldReceive('getSSEStreamConfig')->andReturn([
                'events' => [['data' => 'test']],
                'interval' => 0.01,
                'jitter' => 0.0,
            ]);
            $this->mock->shouldReceive('getStatusCode')->andReturn(200);
            $this->mock->shouldReceive('getHeaders')->andReturn([]);

            $eventReceived = false;
            $onEvent = function () use (&$eventReceived) {
                $eventReceived = true;
            };

            $timerId = null;
            $this->emitter->emit($this->promise, $this->mock, $onEvent, null, $timerId);

            $loop = EventLoop::getInstance();
            $loop->addTimer(0.5, function () use ($loop) {
                $loop->stop();
            });

            $loop->run();

            expect($eventReceived)->toBeTrue();
        });
    });

    describe('Configuration Handling', function () {

        it('uses default interval when not specified', function () {
            $this->mock->shouldReceive('getSSEStreamConfig')->andReturn([
                'events' => [['data' => 'test']],
            ]);
            $this->mock->shouldReceive('getStatusCode')->andReturn(200);
            $this->mock->shouldReceive('getHeaders')->andReturn([]);

            $timerId = null;
            $this->emitter->emit($this->promise, $this->mock, null, null, $timerId);

            expect($timerId)->toBeString();
        });

        it('handles invalid interval values', function () {
            $this->mock->shouldReceive('getSSEStreamConfig')->andReturn([
                'events' => [['data' => 'test']],
                'interval' => 'invalid',
            ]);
            $this->mock->shouldReceive('getStatusCode')->andReturn(200);
            $this->mock->shouldReceive('getHeaders')->andReturn([]);

            $timerId = null;
            $this->emitter->emit($this->promise, $this->mock, null, null, $timerId);

            // Should use default interval
            expect($timerId)->toBeString();
        });

        it('handles invalid jitter values', function () {
            $this->mock->shouldReceive('getSSEStreamConfig')->andReturn([
                'events' => [['data' => 'test']],
                'jitter' => 'invalid',
            ]);
            $this->mock->shouldReceive('getStatusCode')->andReturn(200);
            $this->mock->shouldReceive('getHeaders')->andReturn([]);

            $timerId = null;
            $this->emitter->emit($this->promise, $this->mock, null, null, $timerId);

            expect($timerId)->toBeString();
        });

        it('filters out non-array events', function () {
            $this->mock->shouldReceive('getSSEStreamConfig')->andReturn([
                'events' => [
                    ['data' => 'valid'],
                    'invalid',
                    ['data' => 'also_valid'],
                    123,
                ],
                'interval' => 0.01,
            ]);
            $this->mock->shouldReceive('getStatusCode')->andReturn(200);
            $this->mock->shouldReceive('getHeaders')->andReturn([]);

            $eventCount = 0;
            $onEvent = function () use (&$eventCount) {
                $eventCount++;
            };

            $timerId = null;
            $this->emitter->emit($this->promise, $this->mock, $onEvent, null, $timerId);

            $loop = EventLoop::getInstance();
            $loop->addTimer(0.5, function () use ($loop) {
                $loop->stop();
            });

            $loop->run();

            expect($eventCount)->toBe(2);
        });

        it('handles empty events array', function () {
            $this->mock->shouldReceive('getSSEStreamConfig')->andReturn([
                'events' => [],
                'interval' => 0.01,
            ]);
            $this->mock->shouldReceive('getStatusCode')->andReturn(200);
            $this->mock->shouldReceive('getHeaders')->andReturn([]);

            $timerId = null;
            $this->emitter->emit($this->promise, $this->mock, null, null, $timerId);

            expect($timerId)->toBeString();
        });

        it('handles non-array events configuration', function () {
            $this->mock->shouldReceive('getSSEStreamConfig')->andReturn([
                'events' => 'not-an-array',
                'interval' => 0.01,
            ]);
            $this->mock->shouldReceive('getStatusCode')->andReturn(200);
            $this->mock->shouldReceive('getHeaders')->andReturn([]);
            $this->mock->shouldReceive('shouldFail')->andReturn(false);

            $eventCount = 0;
            $onEvent = function () use (&$eventCount) {
                $eventCount++;
            };

            $timerId = null;
            $this->emitter->emit($this->promise, $this->mock, $onEvent, null, $timerId);

            $loop = EventLoop::getInstance();
            $loop->addTimer(0.1, function () use ($loop) {
                $loop->stop();
            });

            $loop->run();

            expect($eventCount)->toBe(0);
        });
    });

    describe('Timer Management', function () {

        it('sets timer ID via reference parameter', function () {
            $this->mock->shouldReceive('getSSEStreamConfig')->andReturn([
                'events' => [['data' => 'test']],
                'interval' => 0.01,
            ]);
            $this->mock->shouldReceive('getStatusCode')->andReturn(200);
            $this->mock->shouldReceive('getHeaders')->andReturn([]);

            $timerId = null;
            $this->emitter->emit($this->promise, $this->mock, null, null, $timerId);

            expect($timerId)->toBeString()->not->toBeEmpty();
        });

        it('allows timer cancellation', function () {
            $this->mock->shouldReceive('getSSEStreamConfig')->andReturn([
                'events' => [['data' => 'test1'], ['data' => 'test2']],
                'interval' => 0.1,
            ]);
            $this->mock->shouldReceive('getStatusCode')->andReturn(200);
            $this->mock->shouldReceive('getHeaders')->andReturn([]);

            $eventCount = 0;
            $onEvent = function () use (&$eventCount) {
                $eventCount++;
            };

            $timerId = null;
            $this->emitter->emit($this->promise, $this->mock, $onEvent, null, $timerId);

            // Cancel immediately
            $cancelled = EventLoop::getInstance()->cancelTimer($timerId);

            expect($cancelled)->toBeTrue();

            $loop = EventLoop::getInstance();
            $loop->addTimer(0.3, function () use ($loop) {
                $loop->stop();
            });

            $loop->run();

            expect($eventCount)->toBe(0);
        });
    });

    describe('Callback Handling', function () {

        it('works without onEvent callback', function () {
            $this->mock->shouldReceive('getSSEStreamConfig')->andReturn([
                'events' => [['data' => 'test']],
                'interval' => 0.01,
            ]);
            $this->mock->shouldReceive('getStatusCode')->andReturn(200);
            $this->mock->shouldReceive('getHeaders')->andReturn([]);

            $timerId = null;

            expect(fn() => $this->emitter->emit(
                $this->promise,
                $this->mock,
                null,
                null,
                $timerId
            ))->not->toThrow(Exception::class);
        });

        it('works without onError callback', function () {
            $this->mock->shouldReceive('getSSEStreamConfig')->andReturn([
                'events' => [],
                'interval' => 0.01,
                'auto_close' => true,
            ]);
            $this->mock->shouldReceive('getStatusCode')->andReturn(200);
            $this->mock->shouldReceive('getHeaders')->andReturn([]);
            $this->mock->shouldReceive('shouldFail')->andReturn(true);
            $this->mock->shouldReceive('getError')->andReturn('Test error');

            $timerId = null;

            expect(fn() => $this->emitter->emit(
                $this->promise,
                $this->mock,
                null,
                null,
                $timerId
            ))->not->toThrow(Exception::class);
        });
    });
});
