<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Handlers;

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Exceptions\HttpStreamException;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Interfaces\StreamingHandlerInterface;
use Hibla\HttpClient\Stream;
use Hibla\HttpClient\StreamingResponse;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

class StreamingHandler implements StreamingHandlerInterface
{
    /**
     * @inheritDoc
     */
    public function streamRequest(string $url, array $options, ?callable $onChunk = null): PromiseInterface
    {
        /** @var Promise<StreamingResponse> $promise */
        $promise = new Promise();

        $responseStream = fopen('php://temp', 'w+b');
        if ($responseStream === false) {
            $exception = new HttpStreamException('Failed to create response stream', 0, null, $url);
            $exception->setStreamState('stream_creation_failed');
            $promise->reject($exception);

            return $promise;
        }

        /** @var list<string> $headerAccumulator */
        $headerAccumulator = [];

        $curlOnlyOptions = array_filter($options, 'is_int', ARRAY_FILTER_USE_KEY);

        $streamingOptions = array_replace($curlOnlyOptions, [
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use ($responseStream, $onChunk): int {
                fwrite($responseStream, $data);
                if ($onChunk !== null) {
                    $onChunk($data);
                }

                return \strlen($data);
            },
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$headerAccumulator): int {
                $trimmedHeader = trim($header);
                if ($trimmedHeader !== '') {
                    $headerAccumulator[] = $trimmedHeader;
                }

                return \strlen($header);
            },
        ]);

        $requestId = Loop::addHttpRequest(
            $url,
            $streamingOptions,
            function (?string $error, $response, ?int $httpCode, array $headers = [], ?string $httpVersion = null) use ($url, $promise, $responseStream, &$headerAccumulator): void {
                if ($promise->isCancelled()) {
                    fclose($responseStream);

                    return;
                }

                if ($error !== null) {
                    fclose($responseStream);

                    $exception = new NetworkException(
                        "Streaming request failed: {$error}",
                        0,
                        null,
                        $url,
                        $error
                    );
                    $promise->reject($exception);
                } else {
                    rewind($responseStream);
                    $stream = new Stream($responseStream);

                    /** @var array<string, string|list<string>> $formattedHeaders */
                    $formattedHeaders = [];
                    foreach ($headerAccumulator as $header) {
                        if (str_contains($header, ':')) {
                            [$key, $value] = explode(':', $header, 2);
                            $key = trim($key);
                            $value = trim($value);
                            if (isset($formattedHeaders[$key])) {
                                if (\is_array($formattedHeaders[$key])) {
                                    $formattedHeaders[$key][] = $value;
                                } else {
                                    $formattedHeaders[$key] = [$formattedHeaders[$key], $value];
                                }
                            } else {
                                $formattedHeaders[$key] = $value;
                            }
                        }
                    }

                    $streamingResponse = new StreamingResponse($stream, $httpCode ?? 200, $formattedHeaders);

                    if ($httpVersion !== null) {
                        $streamingResponse->setHttpVersion($httpVersion);
                    }

                    $promise->resolve($streamingResponse);
                }
            }
        );

        $promise->onCancel(function () use ($requestId, $responseStream): void {
            Loop::cancelHttpRequest($requestId);
            if (\is_resource($responseStream)) {
                fclose($responseStream);
            }
        });

        return $promise;
    }

    /**
     * @inheritDoc
     */
    public function downloadFile(string $url, string $destination, array $options = []): PromiseInterface
    {
        /** @var Promise<array{file: string, status: int, headers: array<mixed>, protocol_version: string|null, size: int|false}> $promise */
        $promise = new Promise();

        $file = fopen($destination, 'wb');
        if ($file === false) {
            $exception = new HttpStreamException("Cannot open file for writing: {$destination}", 0, null, $url);
            $exception->setStreamState('file_open_failed');
            $promise->reject($exception);

            return $promise;
        }

        $curlOnlyOptions = array_filter($options, 'is_int', ARRAY_FILTER_USE_KEY);

        $downloadOptions = array_replace($curlOnlyOptions, [
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use ($file): int|false {
                return fwrite($file, $data);
            },
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($ch, $downloadSize, $downloaded, $uploadSize, $uploaded) use ($promise): int {
                if ($promise->isCancelled()) {
                    return 1; // Abort download
                }

                return 0;
            },
        ]);

        $requestId = Loop::addHttpRequest(
            $url,
            $downloadOptions,
            function (?string $error, $response, ?int $httpCode, array $headers = [], ?string $httpVersion = null) use ($url, $promise, $file, $destination): void {
                fclose($file);

                if ($promise->isCancelled()) {
                    if (file_exists($destination)) {
                        unlink($destination);
                    }

                    return;
                }

                if ($error !== null) {
                    if (file_exists($destination)) {
                        unlink($destination);
                    }

                    $exception = new NetworkException(
                        "Download failed: {$error}",
                        0,
                        null,
                        $url,
                        $error
                    );
                    $promise->reject($exception);
                } else {
                    $fileSize = file_exists($destination) ? filesize($destination) : 0;
                    $promise->resolve([
                        'file' => $destination,
                        'status' => $httpCode ?? 0,
                        'headers' => $headers,
                        'protocol_version' => $httpVersion,
                        'size' => $fileSize,
                    ]);
                }
            }
        );

        $promise->onCancel(function () use ($requestId, $file, $destination): void {
            Loop::cancelHttpRequest($requestId);
            if (\is_resource($file)) {
                fclose($file);
            }
            if (file_exists($destination)) {
                unlink($destination);
            }
        });

        return $promise;
    }
}
