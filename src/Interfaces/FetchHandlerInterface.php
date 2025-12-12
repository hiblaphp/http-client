<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces;

use Hibla\HttpClient\Response;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\HttpClient\StreamingResponse;
use Hibla\Promise\Interfaces\PromiseInterface;

interface FetchHandlerInterface
{
    /**
     * A flexible, fetch-like method for making HTTP requests with streaming support.
     *
     * This method supports multiple request modes based on the options provided:
     * - Standard HTTP request: Returns a Response
     * - Streaming request (stream: true): Returns a StreamingResponse
     * - Download request (download/save_to: path): Returns download metadata
     * - SSE request (sse: true): Returns an SSEResponse
     *
     * Additional features can be enabled through options:
     * - Retry logic: Configure automatic retries on failures
     * - Caching: Enable response caching with TTL
     * - Custom callbacks: Handle chunks, events, or errors in real-time
     *
     * @param string $url The target URL for the HTTP request
     * @param array<int|string, mixed> $options An associative array of request options including:
     *        - 'method': HTTP method (GET, POST, etc.)
     *        - 'headers': Request headers
     *        - 'body': Request body
     *        - 'stream': Enable streaming mode (bool)
     *        - 'on_chunk'/'onChunk': Callback for streaming chunks
     *        - 'download'/'save_to': File path for downloads (string)
     *        - 'sse': Enable Server-Sent Events mode (bool)
     *        - 'on_event'/'onEvent': SSE event callback
     *        - 'on_error'/'onError': SSE error callback
     *        - 'reconnect': SSE reconnection config (bool|array|SSEReconnectConfig)
     *        - 'retry': Retry configuration (RetryConfig)
     *        - 'cache': Cache configuration (CacheConfig)
     *        - Standard cURL options can also be included
     *
     * @return PromiseInterface<Response>|PromiseInterface<StreamingResponse>|PromiseInterface<array{file: string, status: int, headers: array<mixed>, protocol_version: string|null, size: int|false}>|PromiseInterface<SSEResponse>
     *         A promise that resolves with one of:
     *         - Response: For standard requests
     *         - StreamingResponse: For streaming requests
     *         - array: Download metadata with file path, status, headers, protocol version, and size
     *         - SSEResponse: For Server-Sent Events connections
     *
     *         The promise may reject with:
     *         - NetworkException: On network failures
     *         - HttpStreamException: On streaming errors
     *         - RequestException: On request configuration errors
     *         - InvalidArgumentException: On invalid option values
     */
    public function fetch(string $url, array $options = []): PromiseInterface;

    /**
     * Normalizes fetch options from various formats to cURL options.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  The options to normalize.
     * @param  bool  $ensureSSEHeaders  Whether to ensure SSE-specific headers are set.
     * @return array<int|string, mixed> Normalized cURL options.
     */
    public function normalizeFetchOptions(string $url, array $options, bool $ensureSSEHeaders = false): array;
}
