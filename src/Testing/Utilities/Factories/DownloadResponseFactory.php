<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Utilities\Factories;

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Exceptions\HttpStreamException;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\FileManager;
use Hibla\HttpClient\Testing\Utilities\Handlers\DelayCalculator;
use Hibla\HttpClient\Testing\Utilities\Handlers\NetworkSimulationHandler;
use Hibla\HttpClient\ValueObjects\DownloadProgress;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

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
     * Creates a mock download response with realistic asynchronous chunk delivery.
     *
     * @param MockedRequest $mock The mock configuration.
     * @param string $destination The local path to save the file.
     * @param FileManager $fileManager Manager for tracking temporary files.
     * @param (callable(DownloadProgress): void)|null $onProgress Optional progress callback.
     * @return PromiseInterface<array{file: string, status: int, headers: array<string, string>, size: int, protocol_version: string}>
     */
    public function create(
        MockedRequest $mock,
        string $destination,
        FileManager $fileManager,
        ?callable $onProgress = null
    ): PromiseInterface {
        /** @var Promise<array{file: string, status: int, headers: array<string, string>, size: int, protocol_version: string}> $promise */
        $promise = new Promise();

        $networkConditions = $this->networkHandler->simulate();
        $globalDelay = $this->networkHandler->generateGlobalRandomDelay();
        $totalDelay = $this->delayCalculator->calculateTotalDelay(
            $mock,
            $networkConditions,
            $globalDelay
        );

        $delayPromise = delay($totalDelay);

        $promise->onCancel(function () use ($delayPromise, $destination) {
            $delayPromise->cancel();

            if (file_exists($destination)) {
                @unlink($destination);
            }
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

        $delayPromise->then(function () use ($promise, $mock, $destination, $fileManager, $onProgress) {
            if ($promise->isCancelled()) {
                return;
            }

            try {
                if ($mock->shouldFail()) {
                    $error = $mock->getError() ?? 'Mocked failure';

                    throw new NetworkException($error, 0, null, null, $error);
                }

                $this->ensureDirectoryExists($destination, $fileManager);

                $content = $mock->getBody();
                $file = @fopen($destination, 'wb');

                if ($file === false) {
                    $exception = new HttpStreamException("Cannot open file for writing: {$destination}");
                    $exception->setStreamState('file_write_failed');

                    throw $exception;
                }

                $fileManager->trackFile($destination);

                $this->deliverChunksAsync(
                    $file,
                    $content,
                    0,
                    $mock,
                    $onProgress,
                    $promise,
                    $destination
                );

            } catch (\Exception $e) {
                $promise->reject($e);
            }
        });

        return $promise;
    }

    /**
     * Recursively writes chunks to the file using the Event Loop to simulate network timing.
     *
     * @param resource $file The open file handle.
     * @param string $content The full body content to write.
     * @param int $offset The current byte offset.
     */
    private function deliverChunksAsync(
        $file,
        string $content,
        int $offset,
        MockedRequest $mock,
        ?callable $onProgress,
        Promise $promise,
        string $destination
    ): void {
        if ($promise->isCancelled()) {
            if (\is_resource($file)) {
                fclose($file);
            }

            return;
        }

        $totalSize = \strlen($content);
        $chunkSize = 8192;

        if ($offset >= $totalSize) {
            if (\is_resource($file)) {
                fclose($file);
            }

            $promise->resolve([
                'file' => $destination,
                'status' => $mock->getStatusCode(),
                'headers' => $this->normalizeHeaders($mock->getHeaders()),
                'size' => $totalSize,
                'protocol_version' => '2.0',
            ]);

            return;
        }

        $baseDelay = $mock->getChunkDelay();
        $jitter = $mock->getChunkJitter();

        $actualDelay = $baseDelay;
        if ($jitter > 0 && $baseDelay > 0) {
            $variation = ($baseDelay * $jitter);
            $actualDelay += (mt_rand() / mt_getrandmax() * 2 * $variation) - $variation;
        }

        Loop::addTimer(max(0, $actualDelay), function () use ($file, $content, $offset, $chunkSize, $totalSize, $mock, $onProgress, $promise, $destination) {
            if ($promise->isCancelled()) {
                if (\is_resource($file)) {
                    fclose($file);
                }

                return;
            }

            $chunk = substr($content, $offset, $chunkSize);

            if (fwrite($file, $chunk) === false) {
                fclose($file);
                $promise->reject(new HttpStreamException('Disk write error during mocked download transfer'));

                return;
            }

            $newOffset = $offset + strlen($chunk);

            if ($onProgress !== null) {
                $onProgress(new DownloadProgress($totalSize, $newOffset));
            }

            $this->deliverChunksAsync($file, $content, $newOffset, $mock, $onProgress, $promise, $destination);
        });
    }

    /**
     * Ensures the parent directory of the destination exists.
     */
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

    /**
     * Normalizes headers array to standard string/string pairs.
     *
     * @param array<string, string|array<string>> $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (\is_array($value)) {
                $normalized[$name] = implode(', ', $value);
            } else {
                $normalized[$name] = $value;
            }
        }

        return $normalized;
    }
}
