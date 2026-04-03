<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Execution;

use Hibla\HttpClient\Interfaces\SSE\SSEBuilderInterface;
use Hibla\HttpClient\Interfaces\StreamingResponseInterface;
use Hibla\HttpClient\ValueObjects\DownloadProgress;
use Hibla\Promise\Interfaces\PromiseInterface;

interface ExecutesStreamingInterface
{
    /**
     * Open a streaming GET request.
     *
     * @param  (callable(string): void)|null $onChunk
     * @return PromiseInterface<StreamingResponseInterface>
     */
    public function stream(string $url, ?callable $onChunk = null): PromiseInterface;

    /**
     * Open a streaming POST request.
     *
     * @param  string|resource|array<string, mixed>|null $body
     * @param  (callable(string): void)|null $onChunk
     * @return PromiseInterface<StreamingResponseInterface>
     */
    public function streamPost(string $url, mixed $body = null, ?callable $onChunk = null): PromiseInterface;

    /**
     * Download a remote resource and write it to $destination.
     *
     * @param  string  $destination  Absolute path where the file should be written.
     * @param  (callable(DownloadProgress): void)|null $onProgress
     * @return PromiseInterface<array{
     *     file: string,
     *     status: int,
     *     headers: array<mixed>,
     *     protocol_version: string|null,
     *     size: int|false
     * }>
     */
    public function download(string $url, string $destination, ?callable $onProgress = null): PromiseInterface;

    /**
     * Create a fluent SSE connection builder.
     *
     * @param  string  $url  The SSE endpoint URL.
     * @return SSEBuilderInterface Fluent SSE connection builder.
     */
    public function sse(string $url): SSEBuilderInterface;
}
