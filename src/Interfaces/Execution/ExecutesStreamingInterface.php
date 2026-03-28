<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Execution;

use Hibla\HttpClient\SSE\SSEBuilder;
use Hibla\HttpClient\StreamingResponse;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Contract for streaming, download, and Server-Sent Events dispatch.
 *
 * These methods share the transport configuration of the fluent builder
 * but consume the response body differently from a standard request —
 * incrementally rather than buffered in full.
 */
interface ExecutesStreamingInterface
{
    /**
     * Open a streaming GET request.
     *
     * The response body is not buffered. When $onChunk is provided it is
     * invoked for each chunk of data as it arrives from the server,
     * allowing real-time processing without waiting for the full response.
     *
     * @param  (callable(string): void)|null  $onChunk
     * @return PromiseInterface<StreamingResponse>
     */
    public function stream(string $url, ?callable $onChunk = null): PromiseInterface;

    /**
     * Open a streaming POST request.
     *
     * Behaves identically to stream() but sends a POST body.
     * When $body is null the body already configured on the builder is used.
     *
     * @param  string|resource|array<string, mixed>|null  $body
     * @param  (callable(string): void)|null              $onChunk
     * @return PromiseInterface<StreamingResponse>
     */
    public function streamPost(string $url, mixed $body = null, ?callable $onChunk = null): PromiseInterface;

    /**
     * Download a remote resource and write it to $destination.
     *
     * The promise resolves once the transfer is complete, carrying
     * metadata about the completed download. The destination file is
     * created or overwritten as needed.
     *
     * @param  string  $destination  Absolute path where the file should be written.
     * @return PromiseInterface<array{
     *     file: string,
     *     status: int,
     *     headers: array<mixed>,
     *     protocol_version: string|null,
     *     size: int|false
     * }>
     */
    public function download(string $url, string $destination): PromiseInterface;

    /**
     * Create a fluent SSE connection builder.
     *
     * All authentication, headers, timeout, and proxy settings already
     * configured on the request are forwarded to the SSEBuilder automatically.
     * The builder allows further SSE-specific configuration (reconnection
     * policy, event callbacks, data format) before the connection is opened.
     *
     * When a body has been set on the request the connection is opened
     * with POST; otherwise GET is used.
     *
     * @param  string  $url  The SSE endpoint URL.
     */
    public function sse(string $url): SSEBuilder;
}
