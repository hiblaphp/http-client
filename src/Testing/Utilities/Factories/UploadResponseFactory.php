<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Utilities\Factories;

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Exceptions\HttpStreamException;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\Handlers\DelayCalculator;
use Hibla\HttpClient\Testing\Utilities\Handlers\NetworkSimulationHandler;
use Hibla\HttpClient\ValueObjects\UploadProgress;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

use function Hibla\delay;

class UploadResponseFactory
{
    private NetworkSimulationHandler $networkHandler;
    private DelayCalculator $delayCalculator;

    public function __construct(NetworkSimulationHandler $networkHandler)
    {
        $this->networkHandler = $networkHandler;
        $this->delayCalculator = new DelayCalculator();
    }

    /**
     * Creates a mock upload response with realistic asynchronous progress delivery.
     *
     * @return PromiseInterface<array{url: string, status: int, headers: array<string, string>, protocol_version: string|null}>
     */
    public function create(
        MockedRequest $mock,
        string $source,
        string $url,
        ?callable $onProgress = null
    ): PromiseInterface {
        /** @var Promise<array{url: string, status: int, headers: array<string, string>, protocol_version: string|null}> $promise */
        $promise = new Promise();

        if (! file_exists($source)) {
            $exception = new HttpStreamException("Cannot open file for reading: {$source}", 0, null, $url);
            $exception->setStreamState('file_open_failed');
            $promise->reject($exception);

            return $promise;
        }

        $networkConditions = $this->networkHandler->simulate();
        $globalDelay = $this->networkHandler->generateGlobalRandomDelay();
        $totalDelay = $this->delayCalculator->calculateTotalDelay($mock, $networkConditions, $globalDelay);

        $delayPromise = delay($totalDelay);
        $promise->onCancel($delayPromise->cancel(...));

        if ($networkConditions['should_fail']) {
            $delayPromise->then(function () use ($promise, $networkConditions, $url) {
                if ($promise->isCancelled()) {
                    return;
                }
                $error = $networkConditions['error_message'] ?? 'Network failure';
                $promise->reject(new NetworkException($error, 0, null, $url, $error));
            });

            return $promise;
        }

        $delayPromise->then(function () use ($promise, $mock, $source, $url, $onProgress) {
            if ($promise->isCancelled()) {
                return;
            }

            try {
                if ($mock->shouldFail()) {
                    $error = $mock->getError() ?? 'Mocked failure';

                    throw new NetworkException($error, 0, null, $url, $error);
                }

                $totalSize = filesize($source);
                if ($totalSize === false) {
                    $totalSize = 0;
                }

                if ($totalSize === 0) {
                    if ($onProgress !== null) {
                        $onProgress(new UploadProgress(0, 0));
                    }
                    $promise->resolve($this->buildResponse($mock, $url));

                    return;
                }

                $this->deliverUploadProgressAsync(
                    0,
                    $totalSize,
                    $mock,
                    $onProgress,
                    $promise,
                    $url
                );

            } catch (\Exception $e) {
                $promise->reject($e);
            }
        });

        return $promise;
    }

    /**
     * Recursively simulates the upload progress using the Event Loop.
     */
    private function deliverUploadProgressAsync(
        int $offset,
        int $totalSize,
        MockedRequest $mock,
        ?callable $onProgress,
        Promise $promise,
        string $url
    ): void {
        if ($promise->isCancelled()) {
            return;
        }

        $chunkSize = 8192;

        if ($offset >= $totalSize) {
            $promise->resolve($this->buildResponse($mock, $url));

            return;
        }

        $baseDelay = $mock->getChunkDelay();
        $jitter = $mock->getChunkJitter();

        $actualDelay = $baseDelay;
        if ($jitter > 0 && $baseDelay > 0) {
            $variation = ($baseDelay * $jitter);
            $actualDelay += (mt_rand() / mt_getrandmax() * 2 * $variation) - $variation;
        }

        Loop::addTimer(max(0, $actualDelay), function () use ($offset, $totalSize, $chunkSize, $mock, $onProgress, $promise, $url) {
            if ($promise->isCancelled()) {
                return;
            }

            $newOffset = min($totalSize, $offset + $chunkSize);

            if ($onProgress !== null) {
                $onProgress(new UploadProgress($totalSize, $newOffset));
            }

            $this->deliverUploadProgressAsync($newOffset, $totalSize, $mock, $onProgress, $promise, $url);
        });
    }

    /**
     * @param MockedRequest $mock
     * @param string $url
     * @return array{headers: array, protocol_version: string, status: int, url: string}
     */
    private function buildResponse(MockedRequest $mock, string $url): array
    {
        return [
            'url' => $url,
            'status' => $mock->getStatusCode(),
            'headers' => $this->normalizeHeaders($mock->getHeaders()),
            'protocol_version' => '2.0',
        ];
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[$name] = \is_array($value) ? implode(', ', $value) : $value;
        }

        return $normalized;
    }
}
