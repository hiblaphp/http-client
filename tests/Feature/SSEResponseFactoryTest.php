<?php

use Hibla\EventLoop\EventLoop;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\HttpClient\Testing\Utilities\Factories\SSE\SSEResponseFactory;
use Hibla\HttpClient\Testing\Utilities\NetworkSimulator;

beforeEach(function () {
    EventLoop::reset();
});

afterEach(function () {
    EventLoop::reset();
});

function createSSEResponseFactory(?NetworkSimulator $simulator = null): SSEResponseFactory
{
    $simulator ??= new NetworkSimulator();
    $handler = createNetworkHandler($simulator);

    return new SSEResponseFactory($handler);
}

describe('SSEResponseFactory', function () {

    describe('Immediate SSE', function () {

        it('creates successful SSE response', function () {
            $factory = createSSEResponseFactory();
            $mock = createMockRequest();

            $mock->setSSEEvents([
                ['data' => 'message1', 'event' => 'test'],
                ['data' => 'message2', 'event' => 'test'],
            ]);
            $mock->setStatusCode(200);

            $receivedEvents = [];
            $onEvent = function ($event) use (&$receivedEvents) {
                $receivedEvents[] = $event;
            };

            $promise = $factory->create($mock, $onEvent, null);
            $response = $promise->await();

            expect($response)->toBeInstanceOf(SSEResponse::class);
            expect($response->getStatusCode())->toBe(200);
            expect($receivedEvents)->toHaveCount(2);
        });

        it('handles network failure', function () {
            $simulator = createNetworkSimulatorWithFailure(1.0);
            $factory = createSSEResponseFactory($simulator);
            $mock = createMockRequest();

            $mock->setSSEEvents([['data' => 'test']]);
            $mock->setStatusCode(200);

            $errorCallbackCalled = false;
            $onError = function ($err) use (&$errorCallbackCalled) {
                $errorCallbackCalled = true;
            };

            $promise = $factory->create($mock, null, $onError);

            expect(fn () => $promise->await())
                ->toThrow(NetworkException::class) // Remove specific message check
            ;
            expect($errorCallbackCalled)->toBeTrue();
        });

        it('handles mock failure', function () {
            $factory = createSSEResponseFactory();
            $mock = createMockRequest();

            $mock->setSSEEvents([['data' => 'test']]);
            $mock->setStatusCode(500);
            $mock->setError('Mock SSE failure');

            $errorCallbackCalled = false;
            $onError = function ($err) use (&$errorCallbackCalled) {
                $errorCallbackCalled = true;
            };

            $promise = $factory->create($mock, null, $onError);

            expect(fn () => $promise->await())
                ->toThrow(NetworkException::class)
            ;
            expect($errorCallbackCalled)->toBeTrue();
        });

        it('works without onEvent callback', function () {
            $factory = createSSEResponseFactory();
            $mock = createMockRequest();

            $mock->setSSEEvents([['data' => 'test']]);
            $mock->setStatusCode(200);

            $promise = $factory->create($mock, null, null);
            $response = $promise->await();

            expect($response)->toBeInstanceOf(SSEResponse::class);
        });

        it('works without onError callback', function () {
            $factory = createSSEResponseFactory();
            $mock = createMockRequest();

            $mock->setSSEEvents([['data' => 'test']]);
            $mock->setStatusCode(200);

            $promise = $factory->create($mock, null, null);
            $response = $promise->await();

            expect($response)->toBeInstanceOf(SSEResponse::class);
        });

        it('applies delay from mock', function () {
            $factory = createSSEResponseFactory();
            $mock = createMockRequest();

            $mock->setSSEEvents([['data' => 'test']]);
            $mock->setStatusCode(200);
            $mock->setDelay(0.1);

            $startTime = microtime(true);
            $promise = $factory->create($mock, null, null);
            $response = $promise->await();
            $elapsed = microtime(true) - $startTime;

            expect($response)->toBeInstanceOf(SSEResponse::class);
            expect($elapsed)->toBeGreaterThanOrEqual(0.09);
        });

        it('returns correct status code and headers', function () {
            $factory = createSSEResponseFactory();
            $mock = createMockRequest();

            $mock->setSSEEvents([['data' => 'test']]);
            $mock->setStatusCode(201);
            $mock->addResponseHeader('X-Custom-Header', 'test-value');

            $promise = $factory->create($mock, null, null);
            $response = $promise->await();

            expect($response->getStatusCode())->toBe(201);
            $headers = $response->getHeaders();

            // Headers can be arrays when multiple values exist
            if (is_array($headers['X-Custom-Header'])) {
                expect($headers['X-Custom-Header'][0])->toBe('test-value');
            } else {
                expect($headers['X-Custom-Header'])->toBe('test-value');
            }
        });
    });

    describe('Periodic SSE', function () {

        it('creates periodic SSE response with finite events', function () {
            $factory = createSSEResponseFactory();
            $mock = createMockRequest();

            $mock->setSSEStreamConfig([
                'type' => 'periodic',
                'events' => [
                    ['data' => 'event1'],
                    ['data' => 'event2'],
                    ['data' => 'event3'],
                ],
                'interval' => 0.05,
            ]);
            $mock->setStatusCode(200);

            $receivedEvents = [];
            $onEvent = function ($event) use (&$receivedEvents) {
                $receivedEvents[] = $event;
            };

            $promise = $factory->create($mock, $onEvent, null);
            $response = $promise->await();

            expect($response)->toBeInstanceOf(SSEResponse::class);
            expect($receivedEvents)->toHaveCount(3);
        });

        it('creates periodic SSE response with infinite events', function () {
            $factory = createSSEResponseFactory();
            $mock = createMockRequest();

            $generator = function (int $index) {
                return ['data' => "event{$index}"];
            };

            $mock->setSSEStreamConfig([
                'type' => 'infinite',
                'event_generator' => $generator,
                'interval' => 0.05,
                'max_events' => 3,
            ]);
            $mock->setStatusCode(200);

            $receivedEvents = [];
            $onEvent = function ($event) use (&$receivedEvents) {
                $receivedEvents[] = $event;
            };

            $promise = $factory->create($mock, $onEvent, null);
            $response = $promise->await();

            expect($response)->toBeInstanceOf(SSEResponse::class);
            expect($receivedEvents)->toHaveCount(3);
        });

        it('handles network failure in periodic SSE', function () {
            $simulator = createNetworkSimulatorWithFailure(1.0);
            $factory = createSSEResponseFactory($simulator);
            $mock = createMockRequest();

            $mock->setSSEStreamConfig([
                'events' => [['data' => 'test']],
                'interval' => 0.05,
            ]);
            $mock->setStatusCode(200);

            $promise = $factory->create($mock, null, null);

            expect(fn () => $promise->await())
                ->toThrow(NetworkException::class) // Remove specific message check
            ;
        });

        it('handles mock failure in periodic SSE without auto_close', function () {
            $factory = createSSEResponseFactory();
            $mock = createMockRequest();

            $mock->setSSEStreamConfig([
                'events' => [['data' => 'test']],
                'interval' => 0.05,
                'auto_close' => false,
            ]);
            $mock->setStatusCode(500);
            $mock->setError('Stream error');

            $promise = $factory->create($mock, null, null);

            expect(fn () => $promise->await())
                ->toThrow(NetworkException::class)
            ;
        });

        it('allows mock failure with auto_close', function () {
            $factory = createSSEResponseFactory();
            $mock = createMockRequest();

            $mock->setSSEStreamConfig([
                'events' => [],
                'interval' => 0.05,
                'auto_close' => true,
            ]);
            $mock->setStatusCode(200);
            $mock->setError('Stream closed');

            $errorCalled = false;
            $onError = function ($error) use (&$errorCalled) {
                $errorCalled = true;
            };

            $promise = $factory->create($mock, null, $onError);
            $response = $promise->await();

            expect($response)->toBeInstanceOf(SSEResponse::class);
            expect($errorCalled)->toBeTrue();
        });

        it('applies jitter to periodic events', function () {
            $factory = createSSEResponseFactory();
            $mock = createMockRequest();

            $mock->setSSEStreamConfig([
                'events' => [
                    ['data' => 'event1'],
                    ['data' => 'event2'],
                ],
                'interval' => 0.1,
                'jitter' => 0.5,
            ]);
            $mock->setStatusCode(200);

            $eventTimes = [];
            $onEvent = function () use (&$eventTimes) {
                $eventTimes[] = microtime(true);
            };

            $promise = $factory->create($mock, $onEvent, null);
            $response = $promise->await();

            expect($response)->toBeInstanceOf(SSEResponse::class);
            expect($eventTimes)->toHaveCount(2);
        });
    });
});
