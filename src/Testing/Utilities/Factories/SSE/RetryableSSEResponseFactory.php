<?php

namespace Hibla\Http\Testing\Utilities\Factories\SSE;

use Exception;
use Hibla\EventLoop\EventLoop;
use Hibla\Http\Exceptions\NetworkException;
use Hibla\Http\SSE\SSEReconnectConfig;
use Hibla\Http\SSE\SSEResponse;
use Hibla\Http\Testing\Exceptions\MockException;
use Hibla\Http\Testing\MockedRequest;
use Hibla\Http\Testing\Utilities\Handlers\DelayCalculator;
use Hibla\Http\Testing\Utilities\Handlers\NetworkSimulationHandler;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Throwable;

use function Hibla\delay;

class RetryableSSEResponseFactory
{
    private NetworkSimulationHandler $networkHandler;
    private DelayCalculator $delayCalculator;
    private PeriodicSSEEmitter $periodicEmitter;

    public function __construct(NetworkSimulationHandler $networkHandler)
    {
        $this->networkHandler = $networkHandler;
        $this->delayCalculator = new DelayCalculator();
        $this->periodicEmitter = new PeriodicSSEEmitter();
    }

    /**
     * @return CancellablePromiseInterface<SSEResponse>
     */
    public function create(
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

            $networkConditions = $this->networkHandler->simulate();
            $globalDelay = $this->networkHandler->generateGlobalRandomDelay();
            $delay = $this->delayCalculator->calculateTotalDelay(
                $mock,
                $networkConditions,
                $globalDelay
            );

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

                $result = $this->evaluateAttempt($mock, $networkConditions, $reconnectConfig);

                if ($result['should_fail'] && $result['is_retryable'] && $attempt < $reconnectConfig->maxAttempts) {
                    $attempt++;

                    $retryDelay = $retryInterval !== null
                        ? ($retryInterval / 1000.0)
                        : $reconnectConfig->calculateDelay($attempt);

                    if ($onReconnect !== null) {
                        $onReconnect($attempt, $retryDelay, $result['error_message']);
                    }

                    if ($onError !== null) {
                        $onError($result['error_message']);
                    }

                    $activeDelayPromise = delay($retryDelay);
                    $activeDelayPromise->then($executeAttempt);
                } elseif ($result['should_fail']) {
                    if ($onError !== null) {
                        $onError($result['error_message']);
                    }
                    $promise->reject(new NetworkException(
                        "SSE connection failed after {$currentAttempt} attempt(s): {$result['error_message']}"
                    ));
                } else {
                    try {
                        if ($mock->hasStreamConfig()) {
                            $this->periodicEmitter->emit($promise, $mock, $onEvent, $onError, $periodicTimerId);
                        } else {
                            $immediateEmitter = new ImmediateSSEEmitter();
                            $immediateEmitter->emit($promise, $mock, $onEvent, $lastEventId, $retryInterval);
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
     * @param array{should_fail: bool, error_message?: string} $networkConditions
     * @return array{should_fail: bool, is_retryable: bool, error_message: string}
     */
    private function evaluateAttempt(
        MockedRequest $mock,
        array $networkConditions,
        SSEReconnectConfig $reconnectConfig
    ): array {
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
            $isRetryable = $reconnectConfig->isRetryableError(new Exception($errorMessage)) 
                || $mock->isRetryableFailure();
        }

        return [
            'should_fail' => $shouldFail,
            'is_retryable' => $isRetryable,
            'error_message' => $errorMessage,
        ];
    }
}