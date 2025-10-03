<?php

namespace Hibla\Http\Testing\Utilities;

use Exception;
use Hibla\EventLoop\EventLoop;
use Hibla\Http\Exceptions\HttpException;
use Hibla\Http\Exceptions\HttpStreamException;
use Hibla\Http\Exceptions\NetworkException;
use Hibla\Http\Response;
use Hibla\Http\RetryConfig;
use Hibla\Http\SSE\SSEEvent;
use Hibla\Http\StreamingResponse;
use Hibla\Http\Testing\MockedRequest;
use Hibla\Http\Testing\TestingHttpHandler;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Http\SSE\SSEResponse;
use Hibla\Http\Stream;
use Hibla\Http\Testing\Exceptions\MockException;
use Throwable;

use function Hibla\delay;

class ResponseFactory
{
    private NetworkSimulator $networkSimulator;
    private ?TestingHttpHandler $handler = null;

    public function __construct(NetworkSimulator $networkSimulator, ?TestingHttpHandler $handler = null)
    {
        $this->networkSimulator = $networkSimulator;
        $this->handler = $handler;
    }

    public function createMockedResponse(MockedRequest $mock): PromiseInterface
    {
        /** @var CancellablePromise<Response> $promise */
        $promise = new CancellablePromise;

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

    public function createRetryableMockedResponse(RetryConfig $retryConfig, callable $mockProvider): PromiseInterface
    {
        /** @var CancellablePromise<Response> $promise */
        $promise = new CancellablePromise;
        $attempt = 0;
        $activeDelayPromise = null;

        $promise->setCancelHandler(function () use (&$activeDelayPromise) {
            if ($activeDelayPromise instanceof CancellablePromiseInterface) {
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
                if (!$mock instanceof MockedRequest) {
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

    public function createMockedStream(MockedRequest $mock, ?callable $onChunk, callable $createStream): CancellablePromiseInterface
    {
        /** @var CancellablePromise<StreamingResponse> $promise */
        $promise = new CancellablePromise;

        $this->executeWithNetworkSimulation($promise, $mock, function () use ($mock, $onChunk, $createStream) {
            if ($mock->shouldFail()) {
                throw new HttpException($mock->getError() ?? 'Mocked failure');
            }

            $bodySequence = $mock->getBodySequence();

            if ($onChunk !== null) {
                if (! empty($bodySequence)) {
                    foreach ($bodySequence as $chunk) {
                        $onChunk($chunk);
                    }
                } else {
                    $onChunk($mock->getBody());
                }
            }

            $stream = $createStream($mock->getBody());

            return new StreamingResponse(
                $stream,
                $mock->getStatusCode(),
                $mock->getHeaders()
            );
        });

        return $promise;
    }

    public function createMockedDownload(MockedRequest $mock, string $destination, FileManager $fileManager): CancellablePromiseInterface
    {
        /** @var CancellablePromise<array> $promise */
        $promise = new CancellablePromise;

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

    private function isSSERequested(array $options): bool
    {
        return isset($options['sse']) && $options['sse'] === true;
    }

    private function executeWithNetworkSimulation(CancellablePromise $promise, MockedRequest $mock, callable $callback): void
    {
        $networkConditions = $this->networkSimulator->simulate();
        $mockDelay = $mock->getDelay();
        $globalDelay = $this->handler !== null ? $this->handler->generateGlobalRandomDelay() : 0.0;
        $totalDelay = max($mockDelay, $globalDelay, $networkConditions['delay'] ?? 0);

        $delayPromise = delay($totalDelay);

        $promise->setCancelHandler(function () use ($delayPromise) {
            if ($delayPromise instanceof CancellablePromiseInterface) {
                $delayPromise->cancel();
            }
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
     * Create a mocked SSE response with event streaming.
     */
    public function createMockedSSE(
        MockedRequest $mock,
        ?callable $onEvent,
        ?callable $onError
    ): CancellablePromiseInterface {
        /** @var CancellablePromise<\Hibla\Http\SSE\SSEResponse> $promise */
        $promise = new CancellablePromise;

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

                // Create SSE formatted content
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
                        $event = new SSEEvent(
                            id: $eventData['id'] ?? null,
                            event: $eventData['event'] ?? null,
                            data: $eventData['data'] ?? null,
                            retry: $eventData['retry'] ?? null,
                            rawFields: $eventData
                        );
                        $onEvent($event);
                    }
                }

                if ($mock->shouldFail() && $onError !== null) {
                    $onError($mock->getError() ?? 'SSE connection failed');
                }

                $promise->resolve($sseResponse);
            } catch (Throwable $e) {
                $promise->reject($e);
            }
        });

        return $promise;
    }

    /**
     * Create a retryable mocked SSE response with reconnection support.
     */
    public function createRetryableMockedSSE(
        \Hibla\Http\SSE\SSEReconnectConfig $reconnectConfig,
        callable $mockProvider,
        ?callable $onEvent,
        ?callable $onError,
        ?callable $onReconnect = null
    ): CancellablePromiseInterface {
        /** @var CancellablePromise<\Hibla\Http\SSE\SSEResponse> $promise */
        $promise = new CancellablePromise;
        $attempt = 0;
        $activeDelayPromise = null;
        $lastEventId = null;
        $retryInterval = null;

        $promise->setCancelHandler(function () use (&$activeDelayPromise) {
            if ($activeDelayPromise instanceof CancellablePromiseInterface) {
                $activeDelayPromise->cancel();
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
            &$retryInterval
        ) {
            if ($promise->isCancelled()) {
                return;
            }

            $currentAttempt = $attempt + 1;

            try {
                $mock = $mockProvider($currentAttempt, $lastEventId);
                if (!$mock instanceof MockedRequest) {
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
                &$retryInterval
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

                    // Notify about reconnection attempt
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

                        // Process events and track last event ID
                        if ($onEvent !== null) {
                            foreach ($mock->getSSEEvents() as $eventData) {
                                $event = new SSEEvent(
                                    id: $eventData['id'] ?? null,
                                    event: $eventData['event'] ?? null,
                                    data: $eventData['data'] ?? null,
                                    retry: $eventData['retry'] ?? null,
                                    rawFields: $eventData
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

                        $attempt = 0;

                        if ($mock->shouldFail()) {
                            $errorMessage = $mock->getError() ?? 'SSE connection dropped';
                            $isRetryable = $reconnectConfig->isRetryableError(new Exception($errorMessage)) || $mock->isRetryableFailure();

                            if ($isRetryable && $attempt < $reconnectConfig->maxAttempts) {
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
                                return;
                            }
                        }

                        $promise->resolve($sseResponse);
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
     * Format SSE events into proper SSE protocol format.
     */
    private function formatSSEEvents(array $events): string
    {
        $formatted = [];

        foreach ($events as $event) {
            $lines = [];

            if (isset($event['id'])) {
                $lines[] = "id: {$event['id']}";
            }

            if (isset($event['event'])) {
                $lines[] = "event: {$event['event']}";
            }

            if (isset($event['retry'])) {
                $lines[] = "retry: {$event['retry']}";
            }

            if (isset($event['data'])) {
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
