<?php

namespace Hibla\Http\Testing\Utilities\Factories;

use Exception;
use Hibla\Http\Exceptions\NetworkException;
use Hibla\Http\Response;
use Hibla\Http\Testing\MockedRequest;
use Hibla\Http\Testing\Utilities\Handlers\DelayCalculator;
use Hibla\Http\Testing\Utilities\Handlers\NetworkSimulationHandler;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\PromiseInterface;

use function Hibla\delay;

class StandardResponseFactory
{
    private NetworkSimulationHandler $networkHandler;
    private DelayCalculator $delayCalculator;

    public function __construct(NetworkSimulationHandler $networkHandler)
    {
        $this->networkHandler = $networkHandler;
        $this->delayCalculator = new DelayCalculator();
    }

    /**
     * @return PromiseInterface<Response>
     */
    public function create(MockedRequest $mock): PromiseInterface
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
     * @template TValue
     * @param CancellablePromise<TValue> $promise
     */
    private function executeWithNetworkSimulation(
        CancellablePromise $promise,
        MockedRequest $mock,
        callable $callback
    ): void {
        $networkConditions = $this->networkHandler->simulate();
        $globalDelay = $this->networkHandler->generateGlobalRandomDelay();
        $totalDelay = $this->delayCalculator->calculateTotalDelay(
            $mock,
            $networkConditions,
            $globalDelay
        );

        $delayPromise = delay($totalDelay);

        $promise->setCancelHandler(function () use ($delayPromise) {
            $delayPromise->cancel();
        });

        if ($networkConditions['should_fail']) {
            $delayPromise->then(function () use ($promise, $networkConditions) {
                if ($promise->isCancelled()) {
                    return;
                }
                $promise->reject(
                    new NetworkException($networkConditions['error_message'] ?? 'Network failure')
                );
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
}