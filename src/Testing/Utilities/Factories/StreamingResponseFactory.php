<?php

namespace Hibla\HttpClient\Testing\Utilities\Factories;

use Hibla\HttpClient\Exceptions\HttpException;
use Hibla\HttpClient\Exceptions\HttpStreamException;
use Hibla\HttpClient\StreamingResponse;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\Handlers\DelayCalculator;
use Hibla\HttpClient\Testing\Utilities\Handlers\NetworkSimulationHandler;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Psr\Http\Message\StreamInterface;

use function Hibla\delay;

class StreamingResponseFactory
{
    private NetworkSimulationHandler $networkHandler;
    private DelayCalculator $delayCalculator;

    public function __construct(NetworkSimulationHandler $networkHandler)
    {
        $this->networkHandler = $networkHandler;
        $this->delayCalculator = new DelayCalculator();
    }

    /**
     * Creates a streaming response with the given configuration.
     * 
     * @return CancellablePromiseInterface<StreamingResponse>
     */
    public function create(
        MockedRequest $mock,
        ?callable $onChunk,
        callable $createStream
    ): CancellablePromiseInterface {
        /** @var CancellablePromise<StreamingResponse> $promise */
        $promise = new CancellablePromise();

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
                $promise->reject(new HttpException($networkConditions['error_message'] ?? 'Network failure'));
            });

            return $promise;
        }

        $delayPromise->then(function () use ($promise, $mock, $onChunk, $createStream) {
            if ($promise->isCancelled()) {
                return;
            }

            try {
                if ($mock->shouldFail()) {
                    throw new HttpException($mock->getError() ?? 'Mocked failure');
                }

                $this->processChunks($mock, $onChunk);

                $stream = $createStream($mock->getBody());

                if (! $stream instanceof StreamInterface) {
                    throw new HttpStreamException('Stream creator must return a StreamInterface instance');
                }

                $promise->resolve(new StreamingResponse(
                    $stream,
                    $mock->getStatusCode(),
                    $mock->getHeaders()
                ));
            } catch (\Exception $e) {
                $promise->reject($e);
            }
        });

        return $promise;
    }

    private function processChunks(MockedRequest $mock, ?callable $onChunk): void
    {
        if ($onChunk === null) {
            return;
        }

        $bodySequence = $mock->getBodySequence();

        if ($bodySequence !== []) {
            foreach ($bodySequence as $chunk) {
                $onChunk($chunk);
            }
        } else {
            $onChunk($mock->getBody());
        }
    }
}