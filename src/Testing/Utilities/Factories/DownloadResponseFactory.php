<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Utilities\Factories;

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
     * Creates a download response with the given configuration.
     *
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
                $this->writeFileWithProgress($destination, $mock->getBody(), $fileManager, $onProgress);

                $promise->resolve([
                    'file' => $destination,
                    'status' => $mock->getStatusCode(),
                    'headers' => $this->normalizeHeaders($mock->getHeaders()),
                    'size' => strlen($mock->getBody()),
                    'protocol_version' => '2.0',
                ]);
            } catch (\Exception $e) {
                $promise->reject($e);
            }
        });

        return $promise;
    }

    private function writeFileWithProgress(string $destination, string $content, FileManager $fileManager, ?callable $onProgress): void
    {
        $total = \strlen($content);
        if ($total === 0) {
            if ($onProgress !== null) {
                $onProgress(new DownloadProgress(0, 0));
            }
            if (file_put_contents($destination, '') === false) {
                $exception = new HttpStreamException("Cannot write to file: {$destination}");
                $exception->setStreamState('file_write_failed');

                throw $exception;
            }
            $fileManager->trackFile($destination);

            return;
        }

        $file = @fopen($destination, 'wb');
        if ($file === false) {
            $exception = new HttpStreamException("Cannot write to file: {$destination}");
            $exception->setStreamState('file_write_failed');

            throw $exception;
        }

        $chunkSize = 8192;
        $downloaded = 0;

        for ($i = 0; $i < $total; $i += $chunkSize) {
            $chunk = substr($content, $i, $chunkSize);
            if (fwrite($file, $chunk) === false) {
                fclose($file);
                $exception = new HttpStreamException("Cannot write to file: {$destination}");
                $exception->setStreamState('file_write_failed');

                throw $exception;
            }
            $downloaded += \strlen($chunk);

            if ($onProgress !== null) {
                $onProgress(new DownloadProgress($total, $downloaded));
            }
        }

        fclose($file);
        $fileManager->trackFile($destination);
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

    /**
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
