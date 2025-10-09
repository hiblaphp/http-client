<?php

use Hibla\EventLoop\EventLoop;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\HttpClient\Testing\MockedRequest;

beforeEach(function () {
    EventLoop::reset();
});

afterEach(function () {
    EventLoop::reset();
});



describe('PeriodicSSEEmitter', function () {

    it('throws exception when SSE config is missing', function () {
        $emitter = createPeriodicEmitter();
        $promise = createCancellablePromise();
        $mock = createMockRequest();

        $timerId = null;

        expect(fn() => $emitter->emit(
            $promise,
            $mock,
            null,
            null,
            $timerId
        ))->toThrow(RuntimeException::class, 'SSE stream config is required');
    });

    it('resolves promise with SSEResponse', function () {
        $emitter = createPeriodicEmitter();
        $promise = createCancellablePromise();
        $mock = createMockRequest();

        $mock->setSSEStreamConfig([
            'type' => 'periodic',
            'events' => []
        ]);
        $mock->setStatusCode(200);

        $timerId = null;
        $resolved = false;
        $response = null;

        $promise->then(function ($res) use (&$resolved, &$response) {
            $resolved = true;
            $response = $res;
        });

        $emitter->emit($promise, $mock, null, null, $timerId);

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
            $emitter = createPeriodicEmitter();
            $promise = createCancellablePromise();
            $mock = createMockRequest();

            $events = [
                ['data' => 'event1', 'event' => 'test'],
                ['data' => 'event2', 'event' => 'test'],
            ];

            $mock->setSSEStreamConfig([
                'type' => 'periodic',
                'events' => $events,
                'interval' => 0.01,
            ]);
            $mock->setStatusCode(200);

            $receivedEvents = [];
            $onEvent = function ($event) use (&$receivedEvents) {
                $receivedEvents[] = $event;
            };

            $timerId = null;
            $emitter->emit($promise, $mock, $onEvent, null, $timerId);

            expect($timerId)->toBeString();

            $loop = EventLoop::getInstance();
            $stopTimer = $loop->addTimer(0.5, function () use ($loop) {
                $loop->stop();
            });

            $loop->run();

            expect($receivedEvents)->toHaveCount(2);
        });

        it('applies custom interval', function () {
            $emitter = createPeriodicEmitter();
            $promise = createCancellablePromise();
            $mock = createMockRequest();

            $mock->setSSEStreamConfig([
                'events' => [['data' => 'test']],
                'interval' => 0.05,
            ]);
            $mock->setStatusCode(200);

            $timerId = null;
            $startTime = microtime(true);
            $eventTime = null;

            $onEvent = function () use (&$eventTime) {
                $eventTime = microtime(true);
            };

            $emitter->emit($promise, $mock, $onEvent, null, $timerId);

            $loop = EventLoop::getInstance();
            $loop->addTimer(0.5, function () use ($loop) {
                $loop->stop();
            });

            $loop->run();

            $elapsed = $eventTime - $startTime;
            expect($elapsed)->toBeGreaterThanOrEqual(0.04);
        });

        it('uses default error message when not provided', function () {
            $emitter = createPeriodicEmitter();
            $promise = createCancellablePromise();

            $mock = Mockery::mock(MockedRequest::class);

            $mock->shouldReceive('getSSEStreamConfig')->andReturn([
                'events' => [],
                'interval' => 0.01,
                'auto_close' => true,
            ]);
            $mock->shouldReceive('getStatusCode')->andReturn(200);
            $mock->shouldReceive('getHeaders')->andReturn([]);
            $mock->shouldReceive('shouldFail')->andReturn(true);
            $mock->shouldReceive('getError')->andReturn(null); 

            $errorReceived = null;
            $onError = function ($error) use (&$errorReceived) {
                $errorReceived = $error;
            };

            $timerId = null;
            $emitter->emit($promise, $mock, null, $onError, $timerId);

            $loop = EventLoop::getInstance();
            $loop->addTimer(0.2, function () use ($loop) {
                $loop->stop();
            });

            $loop->run();

            expect($errorReceived)->toBe('Connection closed');

            Mockery::close();
        });
    });

    describe('Infinite Event Stream', function () {

        it('emits infinite events using generator', function () {
            $emitter = createPeriodicEmitter();
            $promise = createCancellablePromise();
            $mock = createMockRequest();

            $generator = function (int $index) {
                return ['data' => "event{$index}", 'event' => 'test'];
            };

            $mock->setSSEStreamConfig([
                'type' => 'infinite',
                'event_generator' => $generator,
                'interval' => 0.01,
                'max_events' => 3,
            ]);
            $mock->setStatusCode(200);

            $receivedEvents = [];
            $onEvent = function ($event) use (&$receivedEvents) {
                $receivedEvents[] = $event;
            };

            $timerId = null;
            $emitter->emit($promise, $mock, $onEvent, null, $timerId);

            $loop = EventLoop::getInstance();
            $loop->addTimer(0.5, function () use ($loop) {
                $loop->stop();
            });

            $loop->run();

            expect($receivedEvents)->toHaveCount(3);
        });

        it('stops after max_events reached', function () {
            $emitter = createPeriodicEmitter();
            $promise = createCancellablePromise();
            $mock = createMockRequest();

            $generator = function (int $index) {
                return ['data' => "event{$index}"];
            };

            $mock->setSSEStreamConfig([
                'type' => 'infinite',
                'event_generator' => $generator,
                'interval' => 0.01,
                'max_events' => 2,
            ]);
            $mock->setStatusCode(200);

            $eventCount = 0;
            $onEvent = function () use (&$eventCount) {
                $eventCount++;
            };

            $timerId = null;
            $emitter->emit($promise, $mock, $onEvent, null, $timerId);

            $loop = EventLoop::getInstance();
            $loop->addTimer(0.3, function () use ($loop) {
                $loop->stop();
            });

            $loop->run();

            expect($eventCount)->toBe(2);
        });

        it('does not require max_events', function () {
            $emitter = createPeriodicEmitter();
            $promise = createCancellablePromise();
            $mock = createMockRequest();

            $generator = function (int $index) {
                return ['data' => "event{$index}"];
            };

            $mock->setSSEStreamConfig([
                'type' => 'infinite',
                'event_generator' => $generator,
                'interval' => 0.01,
            ]);
            $mock->setStatusCode(200);

            $eventCount = 0;
            $onEvent = function () use (&$eventCount) {
                $eventCount++;
            };

            $timerId = null;
            $emitter->emit($promise, $mock, $onEvent, null, $timerId);

            expect($timerId)->toBeString();

            $loop = EventLoop::getInstance();

            $loop->addTimer(0.1, function () use ($loop, $timerId) {
                EventLoop::getInstance()->cancelTimer($timerId);
                $loop->stop();
            });

            $loop->run();

            expect($eventCount)->toBeGreaterThan(0);
        });

        it('ignores non-callable event_generator', function () {
            $emitter = createPeriodicEmitter();
            $promise = createCancellablePromise();
            $mock = createMockRequest();

            $mock->setSSEStreamConfig([
                'type' => 'infinite',
                'event_generator' => 'not-callable',
                'interval' => 0.01,
            ]);
            $mock->setStatusCode(200);

            $eventCount = 0;
            $onEvent = function () use (&$eventCount) {
                $eventCount++;
            };

            $timerId = null;
            $emitter->emit($promise, $mock, $onEvent, null, $timerId);

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
            $emitter = createPeriodicEmitter();
            $promise = createCancellablePromise();
            $mock = createMockRequest();

            $mock->setSSEStreamConfig([
                'events' => [['data' => 'test1'], ['data' => 'test2']],
                'interval' => 0.1,
                'jitter' => 0.5,
            ]);
            $mock->setStatusCode(200);

            $eventTimes = [];
            $onEvent = function () use (&$eventTimes) {
                $eventTimes[] = microtime(true);
            };

            $timerId = null;
            $emitter->emit($promise, $mock, $onEvent, null, $timerId);

            $loop = EventLoop::getInstance();
            $loop->addTimer(1.0, function () use ($loop) {
                $loop->stop();
            });

            $loop->run();

            expect($eventTimes)->toHaveCount(2);
        });

        it('handles zero jitter', function () {
            $emitter = createPeriodicEmitter();
            $promise = createCancellablePromise();
            $mock = createMockRequest();

            $mock->setSSEStreamConfig([
                'events' => [['data' => 'test']],
                'interval' => 0.01,
                'jitter' => 0.0,
            ]);
            $mock->setStatusCode(200);

            $eventReceived = false;
            $onEvent = function () use (&$eventReceived) {
                $eventReceived = true;
            };

            $timerId = null;
            $emitter->emit($promise, $mock, $onEvent, null, $timerId);

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
            $emitter = createPeriodicEmitter();
            $promise = createCancellablePromise();
            $mock = createMockRequest();

            $mock->setSSEStreamConfig([
                'events' => [['data' => 'test']],
            ]);
            $mock->setStatusCode(200);

            $timerId = null;
            $emitter->emit($promise, $mock, null, null, $timerId);

            expect($timerId)->toBeString();
        });

        it('handles invalid interval values', function () {
            $emitter = createPeriodicEmitter();
            $promise = createCancellablePromise();
            $mock = createMockRequest();

            $mock->setSSEStreamConfig([
                'events' => [['data' => 'test']],
                'interval' => 'invalid',
            ]);
            $mock->setStatusCode(200);

            $timerId = null;
            $emitter->emit($promise, $mock, null, null, $timerId);

            expect($timerId)->toBeString();
        });

        it('handles invalid jitter values', function () {
            $emitter = createPeriodicEmitter();
            $promise = createCancellablePromise();
            $mock = createMockRequest();

            $mock->setSSEStreamConfig([
                'events' => [['data' => 'test']],
                'jitter' => 'invalid',
            ]);
            $mock->setStatusCode(200);

            $timerId = null;
            $emitter->emit($promise, $mock, null, null, $timerId);

            expect($timerId)->toBeString();
        });

        it('filters out non-array events', function () {
            $emitter = createPeriodicEmitter();
            $promise = createCancellablePromise();
            $mock = createMockRequest();

            $mock->setSSEStreamConfig([
                'events' => [
                    ['data' => 'valid'],
                    'invalid',
                    ['data' => 'also_valid'],
                    123,
                ],
                'interval' => 0.01,
            ]);
            $mock->setStatusCode(200);

            $eventCount = 0;
            $onEvent = function () use (&$eventCount) {
                $eventCount++;
            };

            $timerId = null;
            $emitter->emit($promise, $mock, $onEvent, null, $timerId);

            $loop = EventLoop::getInstance();
            $loop->addTimer(0.5, function () use ($loop) {
                $loop->stop();
            });

            $loop->run();

            expect($eventCount)->toBe(2);
        });

        it('handles empty events array', function () {
            $emitter = createPeriodicEmitter();
            $promise = createCancellablePromise();
            $mock = createMockRequest();

            $mock->setSSEStreamConfig([
                'events' => [],
                'interval' => 0.01,
            ]);
            $mock->setStatusCode(200);

            $timerId = null;
            $emitter->emit($promise, $mock, null, null, $timerId);

            expect($timerId)->toBeString();
        });

        it('handles non-array events configuration', function () {
            $emitter = createPeriodicEmitter();
            $promise = createCancellablePromise();
            $mock = createMockRequest();

            $mock->setSSEStreamConfig([
                'events' => 'not-an-array',
                'interval' => 0.01,
            ]);
            $mock->setStatusCode(200);

            $eventCount = 0;
            $onEvent = function () use (&$eventCount) {
                $eventCount++;
            };

            $timerId = null;
            $emitter->emit($promise, $mock, $onEvent, null, $timerId);

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
            $emitter = createPeriodicEmitter();
            $promise = createCancellablePromise();
            $mock = createMockRequest();

            $mock->setSSEStreamConfig([
                'events' => [['data' => 'test']],
                'interval' => 0.01,
            ]);
            $mock->setStatusCode(200);

            $timerId = null;
            $emitter->emit($promise, $mock, null, null, $timerId);

            expect($timerId)->toBeString()->not->toBeEmpty();
        });

        it('allows timer cancellation', function () {
            $emitter = createPeriodicEmitter();
            $promise = createCancellablePromise();
            $mock = createMockRequest();

            $mock->setSSEStreamConfig([
                'events' => [['data' => 'test1'], ['data' => 'test2']],
                'interval' => 0.1,
            ]);
            $mock->setStatusCode(200);

            $eventCount = 0;
            $onEvent = function () use (&$eventCount) {
                $eventCount++;
            };

            $timerId = null;
            $emitter->emit($promise, $mock, $onEvent, null, $timerId);

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
            $emitter = createPeriodicEmitter();
            $promise = createCancellablePromise();
            $mock = createMockRequest();

            $mock->setSSEStreamConfig([
                'events' => [['data' => 'test']],
                'interval' => 0.01,
            ]);
            $mock->setStatusCode(200);

            $timerId = null;

            expect(fn() => $emitter->emit(
                $promise,
                $mock,
                null,
                null,
                $timerId
            ))->not->toThrow(Exception::class);
        });

        it('works without onError callback', function () {
            $emitter = createPeriodicEmitter();
            $promise = createCancellablePromise();
            $mock = createMockRequest();

            $mock->setSSEStreamConfig([
                'events' => [],
                'interval' => 0.01,
                'auto_close' => true,
            ]);
            $mock->setStatusCode(200);
            $mock->setError('Test error');

            $timerId = null;

            expect(fn() => $emitter->emit(
                $promise,
                $mock,
                null,
                null,
                $timerId
            ))->not->toThrow(Exception::class);
        });
    });
});
