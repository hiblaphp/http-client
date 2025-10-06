<?php

namespace Hibla\Http\Testing\Utilities\Factories\SSE;

use Hibla\EventLoop\EventLoop;
use Hibla\Http\Exceptions\HttpStreamException;
use Hibla\Http\Exceptions\NetworkException;
use Hibla\Http\SSE\SSEResponse;
use Hibla\Http\Stream;
use Hibla\Http\Testing\MockedRequest;
use Hibla\Http\Testing\Utilities\Formatters\SSEEventFormatter;
use Hibla\Http\Testing\Utilities\Handlers\DelayCalculator;
use Hibla\Http\Testing\Utilities\Handlers\NetworkSimulationHandler;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Throwable;

class SSEResponseFactory
{
    private NetworkSimulationHandler $networkHandler;
    private DelayCalculator $delayCalculator;
    private SSEEventFormatter $formatter;
    private PeriodicSSEEmitter $periodicEmitter;

    public function __construct(NetworkSimulationHandler $networkHandler)
    {
        $this->networkHandler = $networkHandler;
        $this->delayCalculator = new DelayCalculator();
        $this->formatter = new SSEEventFormatter();
        $this->periodicEmitter = new PeriodicSSEEmitter();
    }

    /**
     * @return CancellablePromiseInterface<SSEResponse>
     */
    public function create(
        MockedRequest $mock,
        ?callable $onEvent,
        ?callable $onError
    ): CancellablePromiseInterface {
        if ($mock->hasStreamConfig()) {
            return $this->createPeriodicSSE($mock, $onEvent, $onError);
        }

        return $this->createImmediateSSE($mock, $onEvent, $onError);
    }

    /**
     * @return CancellablePromiseInterface<SSEResponse>
     */
    private function createImmediateSSE(
        MockedRequest $mock,
        ?callable $onEvent,
        ?callable $onError
    ): CancellablePromiseInterface {
        /** @var CancellablePromise<SSEResponse> $promise */
        $promise = new CancellablePromise();

        $networkConditions = $this->networkHandler->simulate();
        $globalDelay = $this->networkHandler->generateGlobalRandomDelay();
        $totalDelay = $this->delayCalculator->calculateTotalDelay(
            $mock,
            $networkConditions,
            $globalDelay
        );

        /** @var string|null $timerId */
        $timerId = null;

        $promise->setCancelHandler(function () use (&$timerId) {
            if ($timerId !== null) {
                EventLoop::getInstance()->cancelTimer($timerId);
            }
        });

        if ($networkConditions['should_fail']) {
            $timerId = EventLoop::getInstance()->addTimer($totalDelay, function () use (
                $promise,
                $networkConditions,
                $onError
            ) {
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

        $timerId = EventLoop::getInstance()->addTimer($totalDelay, function () use (
            $promise,
            $mock,
            $onEvent,
            $onError
        ) {
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

                $sseContent = $this->formatter->formatEvents($mock->getSSEEvents());

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
                        $event = $this->formatter->createSSEEvent($eventData);
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

        $networkConditions = $this->networkHandler->simulate();
        $globalDelay = $this->networkHandler->generateGlobalRandomDelay();
        $initialDelay = $this->delayCalculator->calculateTotalDelay(
            $mock,
            $networkConditions,
            $globalDelay
        );

        /** @var string|null $initialTimerId */
        $initialTimerId = null;
        /** @var string|null $periodicTimerId */
        $periodicTimerId = null;

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
            $initialTimerId = EventLoop::getInstance()->addTimer($initialDelay, function () use (
                $promise,
                $networkConditions,
                $onError
            ) {
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
            $initialTimerId = EventLoop::getInstance()->addTimer($initialDelay, function () use (
                $promise,
                $mock,
                $onError
            ) {
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
            $onEvent,
            $onError,
            &$periodicTimerId
        ) {
            if ($promise->isCancelled()) {
                return;
            }

            try {
                $this->periodicEmitter->emit($promise, $mock, $onEvent, $onError, $periodicTimerId);
            } catch (Throwable $e) {
                if ($onError !== null) {
                    $onError($e->getMessage());
                }
                $promise->reject($e);
            }
        });

        return $promise;
    }
}