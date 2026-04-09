<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Handler;

use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\ValueObjects\RetryConfig;
use Hibla\HttpClient\ValueObjects\UploadProgress;
use Hibla\HttpClient\Interfaces\SSEResponseInterface;
use Hibla\HttpClient\Interfaces\StreamingResponseInterface;
use Hibla\HttpClient\ValueObjects\DownloadProgress;
use Hibla\HttpClient\Interfaces\ResponseInterface;

use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Contract for HTTP handlers responsible for the actual request execution.
 */
interface HttpHandlerInterface
{
     /**
     * The main entry point for sending a request from the Request builder.
     * It intelligently applies caching and retry logic before dispatching the request.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $curlOptions  cURL options for the request.
     * @param  RetryConfig|null  $retryConfig  Optional retry configuration.
     * @return PromiseInterface<ResponseInterface>
     */
    public function sendRequest(string $url, array $curlOptions, ?RetryConfig $retryConfig = null): PromiseInterface;

    /**
     * Creates an SSE (Server-Sent Events) connection with optional reconnection.
     *
     * @param  string  $url  The SSE endpoint URL
     * @param  array<int|string, mixed>  $options  Request options (already prepared by builder)
     * @param  callable(SSEEvent): void|null  $onEvent  Optional callback for each SSE event
     * @param  callable(string): void|null  $onError  Optional callback for connection errors
     * @param  SSEReconnectConfig|null  $reconnectConfig  Optional reconnection configuration
     * @return PromiseInterface<SSEResponseInterface>
     *
     * @internal This method is designed for extension by TestingHttpHandler and internal use.
     */
    public function sse(
        string $url,
        array $options = [],
        ?callable $onEvent = null,
        ?callable $onError = null,
        ?SSEReconnectConfig $reconnectConfig = null
    ): PromiseInterface;

    /**
     * Streams an HTTP response, processing it in chunks.
     *
     * Ideal for large responses that should not be fully loaded into memory.
     *
     * @param  string  $url  The URL to stream from.
     * @param  array<int|string, mixed>  $options  Request options for internal use and testing extensions.
     * @param  callable(string): void|null  $onChunk  An optional callback to execute for each received data chunk.
     * @return PromiseInterface<StreamingResponseInterface>
     */
    public function stream(string $url, array $options = [], ?callable $onChunk = null): PromiseInterface;
  
    /**
     * Asynchronously downloads a file from a URL to a specified destination.
     *
     * @param  string  $url  The URL of the file to download.
     * @param  string  $destination  The local path to save the file.
     * @param  array<int|string, mixed>  $options  Request options for internal use and testing extensions.
     * @param  (callable(DownloadProgress): void)|null $onProgress
     * 
     * @return PromiseInterface<array{file: string, status: int, headers: array<mixed>, protocol_version: string|null, size: int|false}>
     */
    public function download(string $url, string $destination, array $options = [], ?callable $onProgress = null): PromiseInterface;

    /**
     * Asynchronously uploads a file from a local path to a URL.
     *
     * @param  string  $url  The URL to upload the file to.
     * @param  string  $source  The local path of the file to upload.
     * @param  array<int|string, mixed>  $options  Request options for internal use and testing extensions.
     * @param  (callable(UploadProgress): void)|null  $onProgress
     * @return PromiseInterface<array{url: string, status: int, headers: array<mixed>, protocol_version: string|null}>
     */
    public function upload(string $url, string $source, array $options = [], ?callable $onProgress = null): PromiseInterface;
}