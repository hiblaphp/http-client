<?php

namespace Hibla\Http\Testing\Utilities;

use Exception;
use Hibla\EventLoop\EventLoop;
use Hibla\Http\Exceptions\HttpException;
use Hibla\Http\Response;
use Hibla\Http\RetryConfig;
use Hibla\Http\StreamingResponse;
use Hibla\Http\Testing\MockedRequest;
use Hibla\Http\Testing\TestingHttpHandler;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

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
                throw new HttpException($mock->getError() ?? 'Mocked failure');
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
     * Create a retryable mocked response with proper mock consumption
     */
    public function createRetryableMockedResponse(RetryConfig $retryConfig, callable $mockProvider): PromiseInterface
    {
        /** @var CancellablePromise<Response> $promise */
        $promise = new CancellablePromise;
        $attempt = 0;
        /** @var string|null $timerId */
        $timerId = null;

        $promise->setCancelHandler(function () use (&$timerId) {
            if ($timerId !== null) {
                EventLoop::getInstance()->cancelTimer($timerId);
            }
        });

        $executeAttempt = function () use ($retryConfig, $promise, $mockProvider, &$attempt, &$timerId, &$executeAttempt) {
            if ($promise->isCancelled()) {
                return;
            }

            $attempt++;

            try {
                $mock = $mockProvider($attempt);
                if (! $mock instanceof MockedRequest) {
                    throw new Exception('Mock provider must return a MockedRequest instance');
                }
            } catch (Exception $e) {
                $promise->reject(new HttpException('Mock provider error: '.$e->getMessage()));

                return;
            }

            $networkConditions = $this->networkSimulator->simulate();
            $delay = max($networkConditions['delay'] ?? 0, $mock->getDelay());

            $timerId = EventLoop::getInstance()->addTimer($delay, function () use ($retryConfig, $promise, $mock, $networkConditions, $attempt, &$executeAttempt) {
                if ($promise->isCancelled()) {
                    return;
                }

                $shouldFail = false;
                $isRetryable = false;
                $errorMessage = '';

                // Determine if there is a failure condition
                if ($networkConditions['should_fail']) {
                    $shouldFail = true;
                    $errorMessage = $networkConditions['error_message'] ?? 'Network failure';
                    $isRetryable = $retryConfig->isRetryableError($errorMessage);
                } elseif ($mock->shouldFail()) {
                    $shouldFail = true;
                    $errorMessage = $mock->getError() ?? 'Mocked request failure';
                    $isRetryable = $retryConfig->isRetryableError($errorMessage) || $mock->isRetryableFailure();
                } elseif ($mock->getStatusCode() >= 400) { // ** THIS IS THE CRITICAL FIX **
                    $shouldFail = true;
                    $errorMessage = 'Mock responded with status '.$mock->getStatusCode();
                    $isRetryable = in_array($mock->getStatusCode(), $retryConfig->retryableStatusCodes);
                }

                // Decide what to do based on failure status
                if ($shouldFail) {
                    if ($isRetryable && $attempt <= $retryConfig->maxRetries) {
                        $retryDelay = $retryConfig->getDelay($attempt);
                        EventLoop::getInstance()->addTimer($retryDelay, $executeAttempt);
                    } else {
                        $promise->reject(new HttpException("HTTP Request failed after {$attempt} attempts: {$errorMessage}"));
                    }
                } else {
                    // Success
                    $response = new Response($mock->getBody(), $mock->getStatusCode(), $mock->getHeaders());
                    $promise->resolve($response);
                }
            });
        };

        $executeAttempt();

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
                throw new Exception($mock->getError() ?? 'Mocked failure');
            }

            $directory = dirname($destination);
            if (! is_dir($directory)) {
                if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                    throw new Exception("Cannot create directory: {$directory}");
                }
                $fileManager->trackDirectory($directory);
            }

            if (file_put_contents($destination, $mock->getBody()) === false) {
                throw new Exception("Cannot write to file: {$destination}");
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

    private function executeWithNetworkSimulation(CancellablePromise $promise, MockedRequest $mock, callable $callback): void
    {
        $networkConditions = $this->networkSimulator->simulate();

        // Get mock delay (which might be random for persistent mocks)
        $mockDelay = $mock->getDelay();

        // Add global random delay if the handler has it enabled
        $globalDelay = 0.0;
        if ($this->handler !== null) {
            $globalDelay = $this->handler->generateGlobalRandomDelay();
        }

        // Use the maximum of all delays, not sum
        $totalDelay = max($mockDelay, $globalDelay, $networkConditions['delay'] ?? 0);

        /** @var string|null $timerId */
        $timerId = null;

        $promise->setCancelHandler(function () use (&$timerId) {
            if ($timerId !== null) {
                EventLoop::getInstance()->cancelTimer($timerId);
            }
        });

        if ($networkConditions['should_fail']) {
            $timerId = EventLoop::getInstance()->addTimer($totalDelay, function () use ($promise, $networkConditions) {
                if ($promise->isCancelled()) {
                    return;
                }
                $promise->reject(new HttpException($networkConditions['error_message'] ?? 'Network failure'));
            });

            return;
        }

        $timerId = EventLoop::getInstance()->addTimer($totalDelay, function () use ($promise, $callback) {
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
}
