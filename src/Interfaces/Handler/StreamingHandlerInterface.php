<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Handler;

use Hibla\HttpClient\StreamingResponse;
use Hibla\HttpClient\ValueObjects\DownloadProgress;
use Hibla\HttpClient\ValueObjects\UploadProgress;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Contract for non-blocking HTTP streaming and file download operations.
 *
 * Unlike RequestExecutorHandlerInterface, which buffers the entire
 * response body, implementations of this interface expose the body
 * incrementally — either through a chunk callback or by writing
 * directly to a file.
 */
interface StreamingHandlerInterface
{
    /**
     * Open a streaming HTTP request and return a promise that resolves
     * to a StreamingResponse once the response headers have been received.
     *
     * The response body is not buffered. If $onChunk is provided it is
     * invoked synchronously for each chunk of data as it arrives.
     *
     * @param string $url The fully resolved target URL.
     * @param array<int|string, mixed> $options Transport-specific options produced by TransportOptionsBuilderInterface::buildForStreaming().
     * @param (callable(string): void)|null $onChunk Optional callback invoked per data chunk.
     *
     * @return PromiseInterface<StreamingResponse>
     */
    public function streamRequest(
        string $url,
        array $options,
        ?callable $onChunk = null,
    ): PromiseInterface;

    /**
     * Download a remote resource and write it to $destination.
     *
     * Returns a promise that resolves to a metadata array once the
     * transfer is complete.
     *
     * @param string $url The fully resolved target URL.
     * @param string $destination Absolute path for the downloaded file.
     * @param array<int|string, mixed> $options Transport-specific options produced by TransportOptionsBuilderInterface::buildForDownload().
     * @param (callable(DownloadProgress): void)|null $onProgress
     *
     * @return PromiseInterface<array{
     *     file: string,
     *     status: int,
     *     headers: array<mixed>,
     *     protocol_version: string|null,
     *     size: int|false
     * }>
     */
    public function downloadFile(
        string $url,
        string $destination,
        array $options = [],
        ?callable $onProgress = null,
    ): PromiseInterface;

    /**
     * Upload a local file to a remote URL using a non-buffered chunked read.
     *
     * Returns a promise that resolves to a metadata array once the
     * transfer is complete.
     *
     * @param string $url The fully resolved target URL.
     * @param string $source Absolute path of the file to upload.
     * @param array<int|string, mixed> $options Transport-specific options produced by TransportOptionsBuilderInterface.
     * @param (callable(UploadProgress): void)|null $onProgress
     *
     * @return PromiseInterface<array{
     *     url: string,
     *     status: int,
     *     headers: array<mixed>,
     *     protocol_version: string|null
     * }>
     */
    public function uploadFile(
        string $url,
        string $source,
        array $options = [],
        ?callable $onProgress = null,
    ): PromiseInterface;
}
