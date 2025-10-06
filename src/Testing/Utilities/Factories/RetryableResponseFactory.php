<?php

namespace Hibla\Http\Testing\Utilities\Factories;

use Exception;
use Hibla\Http\Exceptions\NetworkException;
use Hibla\Http\Response;
use Hibla\Http\RetryConfig;
use Hibla\Http\Testing\Exceptions\MockException;
use Hibla\Http\Testing\MockedRequest;
use Hibla\Http\Testing\Utilities\Handlers\DelayCalculator;
use Hibla\Http\Testing\Utilities\Handlers\NetworkSimulationHandler;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

use function Hibla\delay;

class RetryableResponseFactory
{
    private NetworkSimulationHandler $networkHandler;
    private DelayCalculator $delayCalculator;

    public function __construct(NetworkSimulationHandler $networkHandler)
    {
        $this->networkHandler = $networkHandler;
        $this->delayCalculator = new DelayCalculator();
    }

    /**
     * Creates a retryable response with the given configuration.
     * 
     * @return PromiseInterface<Response>
     */
    public function create(RetryConfig $retryConfig, callable $mockProvider): PromiseInterface
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
        $executeAttempt = function () use (
            $retryConfig,
            $promise,
            $mockProvider,
            &$attempt,
            &$activeDelayPromise,
            &$executeAttempt
        ) {
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

            $networkConditions = $this->networkHandler->simulate();
            $globalDelay = $this->networkHandler->generateGlobalRandomDelay();
            $delay = $this->delayCalculator->calculateTotalDelay(
                $mock,
                $networkConditions,
                $globalDelay
            );

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

                $result = $this->evaluateAttempt($mock, $networkConditions, $retryConfig);

                if ($result['should_fail'] && $result['is_retryable'] && $attempt < $retryConfig->maxRetries) {
                    $attempt++;
                    $retryDelay = $retryConfig->getDelay($attempt);

                    $activeDelayPromise = delay($retryDelay);
                    $activeDelayPromise->then($executeAttempt);
                } elseif ($result['should_fail']) {
                    $promise->reject(new NetworkException(
                        "HTTP Request failed after {$currentAttempt} attempt(s): {$result['error_message']}"
                    ));
                } else {
                    $response = new Response(
                        $mock->getBody(),
                        $mock->getStatusCode(),
                        $mock->getHeaders()
                    );
                    $promise->resolve($response);
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
        RetryConfig $retryConfig
    ): array {
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

        return [
            'should_fail' => $shouldFail,
            'is_retryable' => $isRetryable,
            'error_message' => $errorMessage,
        ];
    }
}