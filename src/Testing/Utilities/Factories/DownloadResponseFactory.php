<?php

namespace Hibla\Http\Testing\Utilities\Factories;

use Hibla\Http\Exceptions\HttpStreamException;
use Hibla\Http\Exceptions\NetworkException;
use Hibla\Http\Testing\MockedRequest;
use Hibla\Http\Testing\Utilities\FileManager;
use Hibla\Http\Testing\Utilities\Handlers\DelayCalculator;
use Hibla\Http\Testing\Utilities\Handlers\NetworkSimulationHandler;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;

use function Hibla\delay;

class DownloadResponseFactory
{
    private NetworkSimulationHandler $networkHandler;
    private DelayCalculator $delayCalculator;

    public function __construct(NetworkSimulationHandler $networkHandler)
    {
        $this->networkHandler = $networkHandler;
        $this->delayCalculator = new DelayCalculator();
    }

    /**
     * Creates a download response with the given configuration.
     * 
     * @return CancellablePromiseInterface<array{file: string, status: int, headers: array<string, string>, size: int, protocol_version: string}>
     */
    public function create(
        MockedRequest $mock,
        string $destination,
        FileManager $fileManager
    ): CancellablePromiseInterface {
        /** @var CancellablePromise<array{file: string, status: int, headers: array<string, string>, size: int, protocol_version: string}> $promise */
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
                $error = $networkConditions['error_message'] ?? 'Network failure';
                $promise->reject(new NetworkException($error, 0, null, null, $error));
            });

            return $promise;
        }

        $delayPromise->then(function () use ($promise, $mock, $destination, $fileManager) {
            if ($promise->isCancelled()) {
                return;
            }

            try {
                if ($mock->shouldFail()) {
                    $error = $mock->getError() ?? 'Mocked failure';
                    throw new NetworkException($error, 0, null, null, $error);
                }

                $this->ensureDirectoryExists($destination, $fileManager);
                $this->writeFile($destination, $mock->getBody(), $fileManager);

                $promise->resolve([
                    'file' => $destination,
                    'status' => $mock->getStatusCode(),
                    'headers' => $mock->getHeaders(),
                    'size' => strlen($mock->getBody()),
                    'protocol_version' => '2.0',
                ]);
            } catch (\Exception $e) {
                $promise->reject($e);
            }
        });

        return $promise;
    }

    private function ensureDirectoryExists(string $destination, FileManager $fileManager): void
    {
        $directory = dirname($destination);
        
        if (! is_dir($directory)) {
            if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                $exception = new HttpStreamException("Cannot create directory: {$directory}");
                $exception->setStreamState('directory_creation_failed');
                throw $exception;
            }
            $fileManager->trackDirectory($directory);
        }
    }

    private function writeFile(string $destination, string $content, FileManager $fileManager): void
    {
        if (file_put_contents($destination, $content) === false) {
            $exception = new HttpStreamException("Cannot write to file: {$destination}");
            $exception->setStreamState('file_write_failed');
            throw $exception;
        }

        $fileManager->trackFile($destination);
    }
}