<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Utilities\Factories;

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Exceptions\HttpException;
use Hibla\HttpClient\Stream;
use Hibla\HttpClient\StreamingResponse;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\Handlers\DelayCalculator;
use Hibla\HttpClient\Testing\Utilities\Handlers\NetworkSimulationHandler;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

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
     * @return PromiseInterface<StreamingResponse>
     */
    public function create(
        MockedRequest $mock,
        ?callable $onChunk,
        callable $createStream
    ): PromiseInterface {
        /** @var Promise<StreamingResponse> $promise */
        $promise = new Promise();

        $networkConditions = $this->networkHandler->simulate();
        $globalDelay = $this->networkHandler->generateGlobalRandomDelay();
        $totalDelay = $this->delayCalculator->calculateTotalDelay(
            $mock,
            $networkConditions,
            $globalDelay
        );

        $delayPromise = delay($totalDelay);

        $promise->onCancel($delayPromise->cancel(...));

        if ($networkConditions['should_fail']) {
            $delayPromise->then(function () use ($promise, $networkConditions) {
                if ($promise->isCancelled()) {
                    return;
                }
                $promise->reject(new HttpException($networkConditions['error_message'] ?? 'Network failure'));
            });

            return $promise;
        }

        $delayPromise->then(function () use ($promise, $mock, $onChunk) {
            if ($promise->isCancelled()) {
                return;
            }

            try {
                if ($mock->shouldFail()) {
                    throw new HttpException($mock->getError() ?? 'Mocked failure');
                }

                $resource = fopen('php://temp', 'w+b');
                if ($resource === false) {
                    throw new HttpException('Failed to create internal stream buffer');
                }

                $stream = new Stream($resource);

                $promise->resolve(new StreamingResponse(
                    $stream,
                    $mock->getStatusCode(),
                    $mock->getHeaders()
                ));

                $this->processChunks($mock, $onChunk, $stream);

            } catch (\Exception $e) {
                $promise->reject($e);
            }
        });

        return $promise;
    }

    /**
     * Orchestrates the delivery of chunks into the stream buffer.
     */
    private function processChunks(MockedRequest $mock, ?callable $onChunk, Stream $stream): void
    {
        $chunks = $mock->getBodySequence();

        if ($chunks === []) {
            $chunks = [$mock->getBody()];
        }

        $baseDelay = $mock->getChunkDelay();
        $jitter = $mock->getChunkJitter();

        $this->deliverNextChunk($chunks, 0, $baseDelay, $jitter, $onChunk, $stream);
    }

    /**
     * Recursively schedules the next chunk using the Event Loop.
     *
     * @param array<int, string> $chunks
     */
    private function deliverNextChunk(
        array $chunks,
        int $index,
        float $baseDelay,
        float $jitter,
        ?callable $onChunk,
        Stream $stream
    ): void {
        if ($index >= \count($chunks)) {
            $stream->getHandler()->markEof();

            return;
        }

        $actualDelay = $baseDelay;
        if ($jitter > 0 && $baseDelay > 0) {
            $variation = ($baseDelay * $jitter);
            $actualDelay += (mt_rand() / mt_getrandmax() * 2 * $variation) - $variation;
        }

        Loop::addTimer(max(0, $actualDelay), function () use ($chunks, $index, $baseDelay, $jitter, $onChunk, $stream) {
            if ($stream->getHandler()->isClosed()) {
                return;
            }

            $data = $chunks[$index];

            $stream->getHandler()->writeToBuffer($data);

            if ($onChunk !== null) {
                $onChunk($data);
            }

            $this->deliverNextChunk($chunks, $index + 1, $baseDelay, $jitter, $onChunk, $stream);
        });
    }
}
