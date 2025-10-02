<?php

namespace Hibla\Http\Testing\Utilities;

use Exception;
use Hibla\EventLoop\EventLoop;
use Hibla\Http\Exceptions\HttpException;
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
    /**
     * Create a retryable mocked response with proper mock consumption
     */
    public function createRetryableMockedResponse(RetryConfig $retryConfig, callable $mockProvider): PromiseInterface
    {
        echo "\n=== Starting Retryable Mock Response ===\n";
        echo "MaxRetries configured: {$retryConfig->maxRetries}\n";

        /** @var CancellablePromise<Response> $promise */
        $promise = new CancellablePromise;
        $attempt = 0;
        $totalAttempts = 0;
        /** @var string|null $timerId */
        $timerId = null;

        $promise->setCancelHandler(function () use (&$timerId) {
            if ($timerId !== null) {
                EventLoop::getInstance()->cancelTimer($timerId);
            }
        });

        $executeAttempt = function () use ($retryConfig, $promise, $mockProvider, &$attempt, &$totalAttempts, &$timerId, &$executeAttempt) {
            if ($promise->isCancelled()) {
                echo "Promise was cancelled, stopping execution\n";
                return;
            }

            $totalAttempts++;
            $currentAttemptNumber = $totalAttempts;

            echo "\n--- Executing Attempt #{$currentAttemptNumber} (Retry #{$attempt}) ---\n";

            try {
                echo "Calling mockProvider with attempt number: {$currentAttemptNumber}\n";
                $mock = $mockProvider($currentAttemptNumber);
                if (! $mock instanceof MockedRequest) {
                    throw new Exception('Mock provider must return a MockedRequest instance');
                }
                echo "Mock retrieved successfully\n";
                echo "Mock should fail: " . ($mock->shouldFail() ? 'YES' : 'NO') . "\n";
                if ($mock->shouldFail()) {
                    echo "Mock error: " . $mock->getError() . "\n";
                    echo "Mock is retryable: " . ($mock->isRetryableFailure() ? 'YES' : 'NO') . "\n";
                } else {
                    echo "Mock status code: " . $mock->getStatusCode() . "\n";
                    echo "Mock body preview: " . substr($mock->getBody(), 0, 50) . "...\n";
                }
            } catch (Exception $e) {
                echo "ERROR: Mock provider failed: " . $e->getMessage() . "\n";
                $promise->reject(new HttpException('Mock provider error: ' . $e->getMessage()));
                return;
            }

            $networkConditions = $this->networkSimulator->simulate();
            echo "Network simulation - should_fail: " . ($networkConditions['should_fail'] ? 'YES' : 'NO') . "\n";

            // Get mock delay (which might be random for persistent mocks)
            $mockDelay = $mock->getDelay();

            // Add global random delay if the handler has it enabled
            $globalDelay = 0.0;
            if ($this->handler !== null) {
                $globalDelay = $this->handler->generateGlobalRandomDelay();
            }

            // Use the maximum of all delays
            $delay = max($mockDelay, $globalDelay, $networkConditions['delay'] ?? 0);
            echo "Total delay for this attempt: {$delay}s (mock: {$mockDelay}s, global: {$globalDelay}s)\n";

            $timerId = EventLoop::getInstance()->addTimer($delay, function () use (
                $retryConfig,
                $promise,
                $mock,
                $networkConditions,
                &$attempt,
                $totalAttempts,
                &$executeAttempt
            ) {
                if ($promise->isCancelled()) {
                    echo "Promise was cancelled in timer callback\n";
                    return;
                }

                echo "\nTimer fired for attempt #{$totalAttempts}\n";

                $shouldFail = false;
                $isRetryable = false;
                $errorMessage = '';

                // Check network simulation failure first
                if ($networkConditions['should_fail']) {
                    echo "Network simulation caused failure\n";
                    $shouldFail = true;
                    $errorMessage = $networkConditions['error_message'] ?? 'Network failure';
                    $isRetryable = $retryConfig->isRetryableError($errorMessage);
                    echo "Network error is retryable: " . ($isRetryable ? 'YES' : 'NO') . "\n";
                }
                // Check if mock should fail
                elseif ($mock->shouldFail()) {
                    echo "Mock is configured to fail\n";
                    $shouldFail = true;
                    $errorMessage = $mock->getError() ?? 'Mocked request failure';
                    $isRetryable = $retryConfig->isRetryableError($errorMessage) || $mock->isRetryableFailure();
                    echo "Error message: {$errorMessage}\n";
                    echo "Error is retryable: " . ($isRetryable ? 'YES' : 'NO') . "\n";
                }
                // Check for failure status codes
                elseif ($mock->getStatusCode() >= 400) {
                    echo "Mock has failure status code: " . $mock->getStatusCode() . "\n";
                    $shouldFail = true;
                    $errorMessage = 'Mock responded with status ' . $mock->getStatusCode();
                    $isRetryable = in_array($mock->getStatusCode(), $retryConfig->retryableStatusCodes);
                    echo "Status code is retryable: " . ($isRetryable ? 'YES' : 'NO') . "\n";
                } else {
                    echo "Mock represents a successful response\n";
                }

                echo "Decision - shouldFail: " . ($shouldFail ? 'YES' : 'NO') . ", isRetryable: " . ($isRetryable ? 'YES' : 'NO') . "\n";
                echo "Current retry count: {$attempt}, Max retries: {$retryConfig->maxRetries}\n";

                // Decide what to do based on failure status
                if ($shouldFail && $isRetryable) {
                    // Check if we can still retry
                    if ($attempt < $retryConfig->maxRetries) {
                        $attempt++;
                        echo "Will retry - incrementing attempt to {$attempt}\n";
                        $retryDelay = $retryConfig->getDelay($attempt);
                        echo "Scheduling retry with delay: {$retryDelay}s\n";
                        EventLoop::getInstance()->addTimer($retryDelay, $executeAttempt);
                        return;
                    } else {
                        // Exhausted all retries
                        echo "EXHAUSTED all retries - REJECTING\n";
                        $promise->reject(new HttpException(
                            "HTTP Request failed after {$totalAttempts} attempts: {$errorMessage}"
                        ));
                        return;
                    }
                } elseif ($shouldFail) {
                    // Non-retryable failure
                    echo "Non-retryable failure - REJECTING immediately\n";
                    $promise->reject(new HttpException(
                        "HTTP Request failed after {$totalAttempts} attempts: {$errorMessage}"
                    ));
                    return;
                }

                // Success!
                echo "SUCCESS - RESOLVING with response\n";
                echo "Response body: " . $mock->getBody() . "\n";
                $response = new Response($mock->getBody(), $mock->getStatusCode(), $mock->getHeaders());
                $promise->resolve($response);
            });
        };

        EventLoop::getInstance()->nextTick($executeAttempt);

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

    private function isSSERequested(array $options): bool
    {
        return isset($options['sse']) && $options['sse'] === true;
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

        // Check for network simulation failure
        if ($networkConditions['should_fail']) {
            $timerId = EventLoop::getInstance()->addTimer($totalDelay, function () use ($promise, $networkConditions, $onError) {
                if ($promise->isCancelled()) {
                    return;
                }

                $error = $networkConditions['error_message'] ?? 'Network failure';
                if ($onError !== null) {
                    $onError($error);
                }
                $promise->reject(new HttpException($error));
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
                    throw new HttpException($error);
                }

                // Create SSE formatted content
                $sseContent = $this->formatSSEEvents($mock->getSSEEvents());

                $resource = fopen('php://temp', 'w+b');
                if ($resource === false) {
                    throw new \RuntimeException('Failed to create temporary stream');
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
                // Handle multi-line data
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
