<?php

namespace Hibla\Http\Testing\Utilities;

use Exception;

use function Hibla\delay;

use Hibla\EventLoop\EventLoop;
use Hibla\Http\Exceptions\HttpException;
use Hibla\Http\Exceptions\HttpStreamException;
use Hibla\Http\Exceptions\NetworkException;
use Hibla\Http\Response;
use Hibla\Http\RetryConfig;
use Hibla\Http\SSE\SSEEvent;
use Hibla\Http\SSE\SSEReconnectConfig;
use Hibla\Http\SSE\SSEResponse;
use Hibla\Http\Stream;
use Hibla\Http\StreamingResponse;
use Hibla\Http\Testing\Exceptions\MockException;
use Hibla\Http\Testing\MockedRequest;
use Hibla\Http\Testing\TestingHttpHandler;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Psr\Http\Message\StreamInterface;

use Throwable;

class ResponseFactory
{
    private NetworkSimulator $networkSimulator;
    private ?TestingHttpHandler $handler = null;

    public function __construct(NetworkSimulator $networkSimulator, ?TestingHttpHandler $handler = null)
    {
        $this->networkSimulator = $networkSimulator;
        $this->handler = $handler;
    }

    /**
     * @return PromiseInterface<Response>
     */
    public function createMockedResponse(MockedRequest $mock): PromiseInterface
    {
        /** @var CancellablePromise<Response> $promise */
        $promise = new CancellablePromise();

        $this->executeWithNetworkSimulation($promise, $mock, function () use ($mock) {
            if ($mock->shouldFail()) {
                throw new NetworkException($mock->getError() ?? 'Mocked failure');
            }

            return new Response(
                $mock->getBody(),
                $mock->getStatusCode(),
                $mock->getHeaders()
            );
        });

        return $promise;
    }

    /**
     * @return PromiseInterface<Response>
     */
    public function createRetryableMockedResponse(RetryConfig $retryConfig, callable $mockProvider): PromiseInterface
    {
        /** @var CancellablePromise<Response> $promise */
        $promise = new CancellablePromise();
        $attempt = 0;

        /** @var CancellablePromiseInterface<mixed>|null $activeDelayPromise */
        $activeDelayPromise = null;

        $promise->setCancelHandler(function () use (&$activeDelayPromise) {
            if ($activeDelayPromise !== null) {
                $activeDelayPromise->cancel();
            }
        });

        $executeAttempt = null;
        $executeAttempt = function () use ($retryConfig, $promise, $mockProvider, &$attempt, &$activeDelayPromise, &$executeAttempt) {
            if ($promise->isCancelled()) {
                return;
            }

            $currentAttempt = $attempt + 1;

            try {
                $mock = $mockProvider($currentAttempt);
                if (! $mock instanceof MockedRequest) {
                    throw new MockException('Mock provider must return a MockedRequest instance');
                }
            } catch (Exception $e) {
                $promise->reject(new MockException('Mock provider error: ' . $e->getMessage()));

                return;
            }

            $networkConditions = $this->networkSimulator->simulate();
            $mockDelay = $mock->getDelay();
            $globalDelay = $this->handler !== null ? $this->handler->generateGlobalRandomDelay() : 0.0;
            $delay = max($mockDelay, $globalDelay, $networkConditions['delay'] ?? 0);

            $activeDelayPromise = delay($delay);

            $activeDelayPromise->then(function () use (
                $retryConfig,
                $promise,
                $mock,
                $networkConditions,
                &$attempt,
                $currentAttempt,
                &$activeDelayPromise,
                &$executeAttempt
            ) {
                if ($promise->isCancelled()) {
                    return;
                }

                $shouldFail = false;
                $isRetryable = false;
                $errorMessage = '';

                if ($networkConditions['should_fail']) {
                    $shouldFail = true;
                    $errorMessage = $networkConditions['error_message'] ?? 'Network failure';
                    $isRetryable = $retryConfig->isRetryableError($errorMessage);
                } elseif ($mock->shouldFail()) {
                    $shouldFail = true;
                    $errorMessage = $mock->getError() ?? 'Mocked request failure';
                    $isRetryable = $retryConfig->isRetryableError($errorMessage) || $mock->isRetryableFailure();
                } elseif ($mock->getStatusCode() >= 400) {
                    $shouldFail = true;
                    $errorMessage = 'Mock responded with status ' . $mock->getStatusCode();
                    $isRetryable = in_array($mock->getStatusCode(), $retryConfig->retryableStatusCodes, true);
                }

                if ($shouldFail && $isRetryable && $attempt < $retryConfig->maxRetries) {
                    $attempt++;
                    $retryDelay = $retryConfig->getDelay($attempt);

                    $activeDelayPromise = delay($retryDelay);
                    $activeDelayPromise->then($executeAttempt);
                } elseif ($shouldFail) {
                    $promise->reject(new NetworkException(
                        "HTTP Request failed after {$currentAttempt} attempt(s): {$errorMessage}"
                    ));
                } else {
                    $response = new Response($mock->getBody(), $mock->getStatusCode(), $mock->getHeaders());
                    $promise->resolve($response);
                }
            });
        };

        $activeDelayPromise = delay(0);
        $activeDelayPromise->then($executeAttempt);

        return $promise;
    }

    /**
     * @return CancellablePromiseInterface<StreamingResponse>
     */
    public function createMockedStream(MockedRequest $mock, ?callable $onChunk, callable $createStream): CancellablePromiseInterface
    {
        /** @var CancellablePromise<StreamingResponse> $promise */
        $promise = new CancellablePromise();

        $this->executeWithNetworkSimulation($promise, $mock, function () use ($mock, $onChunk, $createStream) {
            if ($mock->shouldFail()) {
                throw new HttpException($mock->getError() ?? 'Mocked failure');
            }

            $bodySequence = $mock->getBodySequence();

            if ($onChunk !== null) {
                if ($bodySequence !== []) {
                    foreach ($bodySequence as $chunk) {
                        $onChunk($chunk);
                    }
                } else {
                    $onChunk($mock->getBody());
                }
            }

            $stream = $createStream($mock->getBody());

            if (! $stream instanceof StreamInterface) {
                throw new HttpStreamException('Stream creator must return a StreamInterface instance');
            }

            return new StreamingResponse(
                $stream,
                $mock->getStatusCode(),
                $mock->getHeaders()
            );
        });

        return $promise;
    }

    /**
     * @return CancellablePromiseInterface<array{file: string, status: int, headers: array<string, string>, size: int, protocol_version: string}>
     */
    public function createMockedDownload(MockedRequest $mock, string $destination, FileManager $fileManager): CancellablePromiseInterface
    {
        /** @var CancellablePromise<array{file: string, status: int, headers: array<string, string>, size: int, protocol_version: string}> $promise */
        $promise = new CancellablePromise();

        $this->executeWithNetworkSimulation($promise, $mock, function () use ($mock, $destination, $fileManager) {
            if ($mock->shouldFail()) {
                $error = $mock->getError() ?? 'Mocked failure';

                throw new NetworkException($error, 0, null, null, $error);
            }

            $directory = dirname($destination);
            if (! is_dir($directory)) {
                if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                    $exception = new HttpStreamException("Cannot create directory: {$directory}");
                    $exception->setStreamState('directory_creation_failed');

                    throw $exception;
                }
                $fileManager->trackDirectory($directory);
            }

            if (file_put_contents($destination, $mock->getBody()) === false) {
                $exception = new HttpStreamException("Cannot write to file: {$destination}");
                $exception->setStreamState('file_write_failed');

                throw $exception;
            }

            $fileManager->trackFile($destination);

            return [
                'file' => $destination,
                'status' => $mock->getStatusCode(),
                'headers' => $mock->getHeaders(),
                'size' => strlen($mock->getBody()),
                'protocol_version' => '2.0',
            ];
        });

        return $promise;
    }

    /**
     * @template TValue
     * @param CancellablePromise<TValue> $promise
     */
    private function executeWithNetworkSimulation(CancellablePromise $promise, MockedRequest $mock, callable $callback): void
    {
        $networkConditions = $this->networkSimulator->simulate();
        $mockDelay = $mock->getDelay();
        $globalDelay = $this->handler !== null ? $this->handler->generateGlobalRandomDelay() : 0.0;
        $totalDelay = max($mockDelay, $globalDelay, $networkConditions['delay'] ?? 0);

        $delayPromise = delay($totalDelay);

        $promise->setCancelHandler(function () use ($delayPromise) {
            $delayPromise->cancel();
        });

        if ($networkConditions['should_fail']) {
            $delayPromise->then(function () use ($promise, $networkConditions) {
                if ($promise->isCancelled()) {
                    return;
                }
                $promise->reject(new NetworkException($networkConditions['error_message'] ?? 'Network failure'));
            });

            return;
        }

        $delayPromise->then(function () use ($promise, $callback) {
            if ($promise->isCancelled()) {
                return;
            }

            try {
                $promise->resolve($callback());
            } catch (Exception $e) {
                $promise->reject($e);
            }
        });
    }

    /**
     * @return CancellablePromiseInterface<SSEResponse>
     */
    public function createMockedSSE(
        MockedRequest $mock,
        ?callable $onEvent,
        ?callable $onError
    ): CancellablePromiseInterface {
        // Check if this mock has streaming configuration
        if ($mock->hasStreamConfig()) {
            return $this->createPeriodicSSE($mock, $onEvent, $onError);
        }

        // Existing immediate SSE response code
        /** @var CancellablePromise<SSEResponse> $promise */
        $promise = new CancellablePromise();

        $networkConditions = $this->networkSimulator->simulate();
        $mockDelay = $mock->getDelay();
        $globalDelay = 0.0;

        if ($this->handler !== null) {
            $globalDelay = $this->handler->generateGlobalRandomDelay();
        }

        $totalDelay = max($mockDelay, $globalDelay, $networkConditions['delay'] ?? 0);

        /** @var string|null $timerId */
        $timerId = null;

        $promise->setCancelHandler(function () use (&$timerId) {
            if ($timerId !== null) {
                EventLoop::getInstance()->cancelTimer($timerId);
            }
        });

        if ($networkConditions['should_fail']) {
            $timerId = EventLoop::getInstance()->addTimer($totalDelay, function () use ($promise, $networkConditions, $onError) {
                if ($promise->isCancelled()) {
                    return;
                }

                $error = $networkConditions['error_message'] ?? 'Network failure';
                if ($onError !== null) {
                    $onError($error);
                }
                $promise->reject(new NetworkException($error));
            });

            return $promise;
        }

        $timerId = EventLoop::getInstance()->addTimer($totalDelay, function () use ($promise, $mock, $onEvent, $onError) {
            if ($promise->isCancelled()) {
                return;
            }

            try {
                if ($mock->shouldFail()) {
                    $error = $mock->getError() ?? 'Mocked SSE failure';
                    if ($onError !== null) {
                        $onError($error);
                    }

                    throw new NetworkException($error);
                }

                $sseContent = $this->formatSSEEvents($mock->getSSEEvents());

                $resource = fopen('php://temp', 'w+b');
                if ($resource === false) {
                    throw new HttpStreamException('Failed to create temporary stream');
                }

                fwrite($resource, $sseContent);
                rewind($resource);
                $stream = new Stream($resource);

                $sseResponse = new SSEResponse(
                    $stream,
                    $mock->getStatusCode(),
                    $mock->getHeaders()
                );

                if ($onEvent !== null) {
                    foreach ($mock->getSSEEvents() as $eventData) {
                        // Convert to the format SSEEvent expects
                        $rawFields = [];
                        if (isset($eventData['id'])) {
                            $rawFields['id'] = [$eventData['id']];
                        }
                        if (isset($eventData['event'])) {
                            $rawFields['event'] = [$eventData['event']];
                        }
                        if (isset($eventData['data'])) {
                            $rawFields['data'] = [$eventData['data']];
                        }
                        if (isset($eventData['retry'])) {
                            $rawFields['retry'] = [(string)$eventData['retry']];
                        }

                        $event = new SSEEvent(
                            id: $eventData['id'] ?? null,
                            event: $eventData['event'] ?? null,
                            data: $eventData['data'] ?? null,
                            retry: $eventData['retry'] ?? null,
                            rawFields: $rawFields
                        );
                        $onEvent($event);
                    }
                }

                $promise->resolve($sseResponse);
            } catch (Throwable $e) {
                $promise->reject($e);
            }
        });

        return $promise;
    }

    /**
     * Create a periodic SSE response using EventLoop timers.
     * This keeps the connection alive and emits events over time.
     *
     * @return CancellablePromiseInterface<SSEResponse>
     */
    private function createPeriodicSSE(
        MockedRequest $mock,
        ?callable $onEvent,
        ?callable $onError
    ): CancellablePromiseInterface {
        /** @var CancellablePromise<SSEResponse> $promise */
        $promise = new CancellablePromise();

        $config = $mock->getSSEStreamConfig();
        if ($config === null) {
            throw new \RuntimeException('SSE stream config is required');
        }

        $networkConditions = $this->networkSimulator->simulate();
        $mockDelay = $mock->getDelay();
        $globalDelay = $this->handler !== null ? $this->handler->generateGlobalRandomDelay() : 0.0;
        $initialDelay = max($mockDelay, $globalDelay, $networkConditions['delay'] ?? 0);

        $type = $config['type'] ?? 'periodic';
        $events = $config['events'] ?? [];

        if (!is_array($events)) {
            $events = [];
        }

        /** @var array<array{data?: string, event?: string, id?: string, retry?: int}> $validatedEvents */
        $validatedEvents = [];
        foreach ($events as $event) {
            if (is_array($event)) {
                $validatedEvents[] = $event;
            }
        }
        $events = $validatedEvents;

        /** @var string|null $initialTimerId */
        $initialTimerId = null;
        /** @var string|null $periodicTimerId */
        $periodicTimerId = null;
        $eventIndex = 0;
        $totalEvents = count($events);

        $promise->setCancelHandler(function () use (&$initialTimerId, &$periodicTimerId) {
            if ($initialTimerId !== null) {
                EventLoop::getInstance()->cancelTimer($initialTimerId);
                $initialTimerId = null;
            }
            if ($periodicTimerId !== null) {
                EventLoop::getInstance()->cancelTimer($periodicTimerId);
                $periodicTimerId = null;
            }
        });

        if ($networkConditions['should_fail']) {
            $initialTimerId = EventLoop::getInstance()->addTimer($initialDelay, function () use ($promise, $networkConditions, $onError) {
                if ($promise->isCancelled()) {
                    return;
                }

                $error = $networkConditions['error_message'] ?? 'Network failure';
                if ($onError !== null) {
                    $onError($error);
                }
                $promise->reject(new NetworkException($error));
            });

            return $promise;
        }

        $autoClose = isset($config['auto_close']) && is_bool($config['auto_close']) ? $config['auto_close'] : false;
        if ($mock->shouldFail() && !$autoClose) {
            $initialTimerId = EventLoop::getInstance()->addTimer($initialDelay, function () use ($promise, $mock, $onError) {
                if ($promise->isCancelled()) {
                    return;
                }

                $error = $mock->getError() ?? 'Mocked SSE failure';
                if ($onError !== null) {
                    $onError($error);
                }
                $promise->reject(new NetworkException($error));
            });

            return $promise;
        }

        $initialTimerId = EventLoop::getInstance()->addTimer($initialDelay, function () use (
            $promise,
            $mock,
            $config,
            $type,
            &$events,
            &$eventIndex,
            &$totalEvents,
            $onEvent,
            $onError,
            &$periodicTimerId
        ) {
            if ($promise->isCancelled()) {
                return;
            }

            try {
                $resource = fopen('php://temp', 'w+b');
                if ($resource === false) {
                    throw new HttpStreamException('Failed to create temporary stream');
                }

                $stream = new Stream($resource);
                $sseResponse = new SSEResponse(
                    $stream,
                    $mock->getStatusCode(),
                    $mock->getHeaders()
                );

                $promise->resolve($sseResponse);

                $interval = isset($config['interval']) && (is_float($config['interval']) || is_int($config['interval']))
                    ? (float)$config['interval']
                    : 1.0;
                $jitter = isset($config['jitter']) && (is_float($config['jitter']) || is_int($config['jitter']))
                    ? (float)$config['jitter']
                    : 0.0;

                if ($type === 'infinite' && isset($config['event_generator']) && is_callable($config['event_generator'])) {
                    $eventGenerator = $config['event_generator'];
                    $maxEvents = isset($config['max_events']) && is_int($config['max_events']) ? $config['max_events'] : null;

                    $periodicTimerId = EventLoop::getInstance()->addPeriodicTimer(
                        interval: $interval,
                        callback: function () use (
                            $eventGenerator,
                            &$eventIndex,
                            $maxEvents,
                            $onEvent,
                            $jitter,
                            $interval,
                            &$periodicTimerId
                        ) {
                            if ($maxEvents !== null && $eventIndex >= $maxEvents) {
                                if ($periodicTimerId !== null) {
                                    EventLoop::getInstance()->cancelTimer($periodicTimerId);
                                    $periodicTimerId = null;
                                }
                                return;
                            }

                            $eventData = $eventGenerator($eventIndex);
                            if (is_array($eventData)) {
                                /** @var array{data?: string, event?: string, id?: string, retry?: int} $eventData */
                                $this->emitSSEEvent($eventData, $onEvent);
                            }
                            $eventIndex++;

                            // Apply jitter by sleeping briefly
                            if ($jitter > 0) {
                                $jitterAmount = $interval * $jitter;
                                $randomJitter = (mt_rand() / mt_getrandmax()) * 2 * $jitterAmount - $jitterAmount;
                                if ($randomJitter > 0) {
                                    usleep((int)($randomJitter * 1000000));
                                }
                            }
                        },
                        maxExecutions: $maxEvents
                    );
                } else {
                    // For finite periodic events (sseWithPeriodicEvents, sseWithLimitedEvents, ssePeriodicThenDisconnect)
                    $autoClose = isset($config['auto_close']) && is_bool($config['auto_close']) ? $config['auto_close'] : false;

                    $periodicTimerId = EventLoop::getInstance()->addPeriodicTimer(
                        interval: $interval,
                        callback: function () use (
                            &$events,
                            &$eventIndex,
                            &$totalEvents,
                            $onEvent,
                            $onError,
                            $mock,
                            $autoClose,
                            $jitter,
                            $interval,
                            &$periodicTimerId
                        ) {
                            if ($eventIndex >= $totalEvents) {
                                if ($periodicTimerId !== null) {
                                    EventLoop::getInstance()->cancelTimer($periodicTimerId);
                                    $periodicTimerId = null;
                                }

                                // Check if we should trigger an error after all events
                                if ($mock->shouldFail() && $autoClose) {
                                    $error = $mock->getError() ?? 'Connection closed';
                                    if ($onError !== null) {
                                        $onError($error);
                                    }
                                }
                                return;
                            }

                            $eventData = $events[$eventIndex];
                            /** @var array{data?: string, event?: string, id?: string, retry?: int} $eventData */
                            $this->emitSSEEvent($eventData, $onEvent);
                            $eventIndex++;

                            // Apply jitter
                            if ($jitter > 0) {
                                $jitterAmount = $interval * $jitter;
                                $randomJitter = (mt_rand() / mt_getrandmax()) * 2 * $jitterAmount - $jitterAmount;
                                if ($randomJitter > 0) {
                                    usleep((int)($randomJitter * 1000000));
                                }
                            }
                        },
                        maxExecutions: $totalEvents
                    );
                }
            } catch (Throwable $e) {
                if ($onError !== null) {
                    $onError($e->getMessage());
                }
                $promise->reject($e);
            }
        });

        return $promise;
    }

    /**
     * @param array{data?: string, event?: string, id?: string, retry?: int} $eventData
     */
    private function emitSSEEvent(array $eventData, ?callable $onEvent): void
    {
        if ($onEvent === null) {
            return;
        }

        $rawFields = [];
        if (isset($eventData['id'])) {
            $rawFields['id'] = [$eventData['id']];
        }
        if (isset($eventData['event'])) {
            $rawFields['event'] = [$eventData['event']];
        }
        if (isset($eventData['data'])) {
            $rawFields['data'] = [$eventData['data']];
        }
        if (isset($eventData['retry'])) {
            $rawFields['retry'] = [(string)$eventData['retry']];
        }

        $event = new SSEEvent(
            id: $eventData['id'] ?? null,
            event: $eventData['event'] ?? null,
            data: $eventData['data'] ?? null,
            retry: $eventData['retry'] ?? null,
            rawFields: $rawFields
        );

        $onEvent($event);
    }

    /**
     * @return CancellablePromiseInterface<SSEResponse>
     */
    public function createRetryableMockedSSE(
        SSEReconnectConfig $reconnectConfig,
        callable $mockProvider,
        ?callable $onEvent,
        ?callable $onError,
        ?callable $onReconnect = null
    ): CancellablePromiseInterface {
        /** @var CancellablePromise<SSEResponse> $promise */
        $promise = new CancellablePromise();
        $attempt = 0;

        /** @var CancellablePromiseInterface<mixed>|null $activeDelayPromise */
        $activeDelayPromise = null;
        /** @var string|null $periodicTimerId */
        $periodicTimerId = null;
        $lastEventId = null;
        $retryInterval = null;

        $promise->setCancelHandler(function () use (&$activeDelayPromise, &$periodicTimerId) {
            if ($activeDelayPromise !== null) {
                $activeDelayPromise->cancel();
            }
            if ($periodicTimerId !== null) {
                EventLoop::getInstance()->cancelTimer($periodicTimerId);
                $periodicTimerId = null;
            }
        });

        $executeAttempt = null;
        $executeAttempt = function () use (
            $reconnectConfig,
            $promise,
            $mockProvider,
            $onEvent,
            $onError,
            $onReconnect,
            &$attempt,
            &$activeDelayPromise,
            &$executeAttempt,
            &$lastEventId,
            &$retryInterval,
            &$periodicTimerId
        ) {
            if ($promise->isCancelled()) {
                return;
            }

            $currentAttempt = $attempt + 1;

            try {
                $mock = $mockProvider($currentAttempt, $lastEventId);
                if (! $mock instanceof MockedRequest) {
                    throw new MockException('Mock provider must return a MockedRequest instance');
                }
            } catch (Exception $e) {
                $promise->reject(new MockException('Mock provider error: ' . $e->getMessage()));
                return;
            }

            $networkConditions = $this->networkSimulator->simulate();
            $mockDelay = $mock->getDelay();
            $globalDelay = $this->handler !== null ? $this->handler->generateGlobalRandomDelay() : 0.0;
            $delay = max($mockDelay, $globalDelay, $networkConditions['delay'] ?? 0);

            $activeDelayPromise = delay($delay);

            $activeDelayPromise->then(function () use (
                $reconnectConfig,
                $promise,
                $mock,
                $networkConditions,
                $onEvent,
                $onError,
                $onReconnect,
                &$attempt,
                $currentAttempt,
                &$activeDelayPromise,
                &$executeAttempt,
                &$lastEventId,
                &$retryInterval,
                &$periodicTimerId
            ) {
                if ($promise->isCancelled()) {
                    return;
                }

                $shouldFail = false;
                $isRetryable = false;
                $errorMessage = '';

                if ($networkConditions['should_fail']) {
                    $shouldFail = true;
                    $errorMessage = $networkConditions['error_message'] ?? 'Network failure';
                    $isRetryable = $reconnectConfig->isRetryableError(new Exception($errorMessage));
                } elseif ($mock->shouldFail()) {
                    $shouldFail = true;
                    $errorMessage = $mock->getError() ?? 'SSE connection failed';
                    $isRetryable = $reconnectConfig->isRetryableError(new Exception($errorMessage)) || $mock->isRetryableFailure();
                }

                if ($shouldFail && $isRetryable && $attempt < $reconnectConfig->maxAttempts) {
                    $attempt++;

                    $retryDelay = $retryInterval !== null
                        ? ($retryInterval / 1000.0)
                        : $reconnectConfig->calculateDelay($attempt);

                    if ($onReconnect !== null) {
                        $onReconnect($attempt, $retryDelay, $errorMessage);
                    }

                    if ($onError !== null) {
                        $onError($errorMessage);
                    }

                    $activeDelayPromise = delay($retryDelay);
                    $activeDelayPromise->then($executeAttempt);
                } elseif ($shouldFail) {
                    if ($onError !== null) {
                        $onError($errorMessage);
                    }
                    $promise->reject(new NetworkException(
                        "SSE connection failed after {$currentAttempt} attempt(s): {$errorMessage}"
                    ));
                } else {
                    try {
                        // **KEY FIX: Check if this is a periodic/streaming SSE**
                        if ($mock->hasStreamConfig()) {
                            $this->setupPeriodicSSEAfterRetry(
                                $promise,
                                $mock,
                                $onEvent,
                                $onError,
                                $periodicTimerId
                            );
                        } else {
                            // Original immediate SSE response logic
                            $this->emitImmediateSSEEvents(
                                $promise,
                                $mock,
                                $onEvent,
                                $lastEventId,
                                $retryInterval
                            );
                        }

                        $attempt = 0;
                    } catch (Throwable $e) {
                        if ($onError !== null) {
                            $onError($e->getMessage());
                        }
                        $promise->reject($e);
                    }
                }
            });
        };

        $activeDelayPromise = delay(0);
        $activeDelayPromise->then($executeAttempt);

        return $promise;
    }

    /**
     * Setup periodic SSE streaming after successful retry
     * @param CancellablePromise<SSEResponse> $promise
     * @param MockedRequest $mock
     * @param callable|null $onEvent
     * @param callable|null $onError
     * @param string|null $periodicTimerId
     * @param-out string $periodicTimerId
     */
    private function setupPeriodicSSEAfterRetry(
        CancellablePromise $promise,
        MockedRequest $mock,
        ?callable $onEvent,
        ?callable $onError,
        ?string &$periodicTimerId
    ): void {
        $config = $mock->getSSEStreamConfig();
        if ($config === null) {
            throw new \RuntimeException('SSE stream config is required');
        }

        $resource = fopen('php://temp', 'w+b');
        if ($resource === false) {
            throw new HttpStreamException('Failed to create temporary stream');
        }

        $stream = new Stream($resource);
        $sseResponse = new SSEResponse(
            $stream,
            $mock->getStatusCode(),
            $mock->getHeaders()
        );

        $promise->resolve($sseResponse);

        // Setup periodic event emission
        $type = $config['type'] ?? 'periodic';
        $interval = isset($config['interval']) && (is_float($config['interval']) || is_int($config['interval']))
            ? (float)$config['interval']
            : 1.0;
        $jitter = isset($config['jitter']) && (is_float($config['jitter']) || is_int($config['jitter']))
            ? (float)$config['jitter']
            : 0.0;

        if ($type === 'infinite' && isset($config['event_generator']) && is_callable($config['event_generator'])) {
            $eventGenerator = $config['event_generator'];
            $maxEvents = isset($config['max_events']) && is_int($config['max_events']) ? $config['max_events'] : null;
            $eventIndex = 0;

            $periodicTimerId = EventLoop::getInstance()->addPeriodicTimer(
                interval: $interval,
                callback: function () use (
                    $eventGenerator,
                    &$eventIndex,
                    $maxEvents,
                    $onEvent,
                    $jitter,
                    $interval,
                    &$periodicTimerId
                ) {
                    if ($maxEvents !== null && $eventIndex >= $maxEvents) {
                        if ($periodicTimerId !== null) {
                            EventLoop::getInstance()->cancelTimer($periodicTimerId);
                            $periodicTimerId = null;
                        }
                        return;
                    }

                    $eventData = $eventGenerator($eventIndex);
                    if (is_array($eventData)) {
                        /** @var array{data?: string, event?: string, id?: string, retry?: int} $eventData */
                        $this->emitSSEEvent($eventData, $onEvent);
                    }
                    $eventIndex++;

                    if ($jitter > 0) {
                        $jitterAmount = $interval * $jitter;
                        $randomJitter = (mt_rand() / mt_getrandmax()) * 2 * $jitterAmount - $jitterAmount;
                        if ($randomJitter > 0) {
                            usleep((int)($randomJitter * 1000000));
                        }
                    }
                },
                maxExecutions: $maxEvents
            );
        } else {
            // Finite periodic events
            $events = $config['events'] ?? [];
            if (!is_array($events)) {
                $events = [];
            }

            /** @var array<array{data?: string, event?: string, id?: string, retry?: int}> $validatedEvents */
            $validatedEvents = [];
            foreach ($events as $event) {
                if (is_array($event)) {
                    $validatedEvents[] = $event;
                }
            }
            $events = $validatedEvents;
            $eventIndex = 0;
            $totalEvents = count($events);
            $autoClose = isset($config['auto_close']) && is_bool($config['auto_close']) ? $config['auto_close'] : false;

            $periodicTimerId = EventLoop::getInstance()->addPeriodicTimer(
                interval: $interval,
                callback: function () use (
                    &$events,
                    &$eventIndex,
                    &$totalEvents,
                    $onEvent,
                    $onError,
                    $mock,
                    $autoClose,
                    $jitter,
                    $interval,
                    &$periodicTimerId
                ) {
                    if ($eventIndex >= $totalEvents) {
                        if ($periodicTimerId !== null) {
                            EventLoop::getInstance()->cancelTimer($periodicTimerId);
                            $periodicTimerId = null;
                        }

                        if ($mock->shouldFail() && $autoClose) {
                            $error = $mock->getError() ?? 'Connection closed';
                            if ($onError !== null) {
                                $onError($error);
                            }
                        }
                        return;
                    }

                    $eventData = $events[$eventIndex];
                    /** @var array{data?: string, event?: string, id?: string, retry?: int} $eventData */
                    $this->emitSSEEvent($eventData, $onEvent);
                    $eventIndex++;

                    if ($jitter > 0) {
                        $jitterAmount = $interval * $jitter;
                        $randomJitter = (mt_rand() / mt_getrandmax()) * 2 * $jitterAmount - $jitterAmount;
                        if ($randomJitter > 0) {
                            usleep((int)($randomJitter * 1000000));
                        }
                    }
                },
                maxExecutions: $totalEvents
            );
        }
    }

    /**
     * Emit all SSE events immediately (for non-streaming SSE)
     * @param CancellablePromise<SSEResponse> $promise
     * @param MockedRequest $mock
     * @param callable|null $onEvent
     * @param string|null &$lastEventId
     * @param int|null &$retryInterval
     */
    private function emitImmediateSSEEvents(
        CancellablePromise $promise,
        MockedRequest $mock,
        ?callable $onEvent,
        ?string &$lastEventId,
        ?int &$retryInterval
    ): void {
        $sseContent = $this->formatSSEEvents($mock->getSSEEvents());

        $resource = fopen('php://temp', 'w+b');
        if ($resource === false) {
            throw new HttpStreamException('Failed to create temporary stream');
        }

        fwrite($resource, $sseContent);
        rewind($resource);
        $stream = new Stream($resource);

        $sseResponse = new SSEResponse(
            $stream,
            $mock->getStatusCode(),
            $mock->getHeaders()
        );

        if ($onEvent !== null) {
            foreach ($mock->getSSEEvents() as $eventData) {
                $rawFields = [];
                if (isset($eventData['id'])) {
                    $rawFields['id'] = [$eventData['id']];
                }
                if (isset($eventData['event'])) {
                    $rawFields['event'] = [$eventData['event']];
                }
                if (isset($eventData['data'])) {
                    $rawFields['data'] = [$eventData['data']];
                }
                if (isset($eventData['retry'])) {
                    $rawFields['retry'] = [(string)$eventData['retry']];
                }

                $event = new SSEEvent(
                    id: $eventData['id'] ?? null,
                    event: $eventData['event'] ?? null,
                    data: $eventData['data'] ?? null,
                    retry: $eventData['retry'] ?? null,
                    rawFields: $rawFields
                );

                if ($event->id !== null) {
                    $lastEventId = $event->id;
                }

                if ($event->retry !== null) {
                    $retryInterval = $event->retry;
                }

                $onEvent($event);
            }
        }

        $promise->resolve($sseResponse);
    }

    /**
     * @param array<array{id?: string, event?: string, data?: string, retry?: int}> $events
     */
    private function formatSSEEvents(array $events): string
    {
        $formatted = [];

        foreach ($events as $event) {
            $lines = [];

            if (isset($event['id']) && is_string($event['id'])) {
                $lines[] = "id: {$event['id']}";
            }

            if (isset($event['event']) && is_string($event['event'])) {
                $lines[] = "event: {$event['event']}";
            }

            if (isset($event['retry']) && is_int($event['retry'])) {
                $lines[] = 'retry: ' . (string)$event['retry'];
            }

            if (isset($event['data']) && is_string($event['data'])) {
                $dataLines = explode("\n", $event['data']);
                foreach ($dataLines as $line) {
                    $lines[] = "data: {$line}";
                }
            }

            $formatted[] = implode("\n", $lines) . "\n\n";
        }

        return implode('', $formatted);
    }
}
