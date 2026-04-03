<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Utilities\Factories;

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
     * Creates a mock upload response.
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

                if ($onProgress !== null) {
                    $total = filesize($source);
                    if ($total === false) {
                        $total = 0;
                    }

                    if ($total === 0) {
                        $onProgress(new UploadProgress(0, 0));
                    } else {
                        $chunkSize = 8192;
                        for ($i = 0; $i < $total; $i += $chunkSize) {
                            $uploaded = min($total, $i + $chunkSize);
                            $onProgress(new UploadProgress($total, $uploaded));
                        }
                    }
                }

                $promise->resolve([
                    'url' => $url,
                    'status' => $mock->getStatusCode(),
                    'headers' => $this->normalizeHeaders($mock->getHeaders()),
                    'protocol_version' => '2.0',
                ]);
            } catch (\Exception $e) {
                $promise->reject($e);
            }
        });

        return $promise;
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
