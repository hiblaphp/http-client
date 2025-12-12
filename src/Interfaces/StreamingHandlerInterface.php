<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces;

use Hibla\HttpClient\StreamingResponse;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Interface for handling non-blocking HTTP streaming operations.
 */
interface StreamingHandlerInterface
{
    /**
     * Creates a streaming HTTP request with optional real-time chunk processing.
     *
     * @param string $url The URL to stream from
     * @param array<int|string, mixed> $options Request configuration options for streaming.
     *                                           Implementations may accept various options such as:
     *                                           - HTTP method, headers, authentication
     *                                           - Timeout and connection settings
     *                                           - SSL/TLS verification
     *                                           - Custom implementation-specific options
     * @param callable|null $onChunk Optional callback to process each chunk as it arrives.
     *                                Signature: function(string $data): void
     *
     * @return PromiseInterface<StreamingResponse> A promise that resolves to a StreamingResponse
     *                                              or rejects with HttpStreamException or NetworkException
     */
    public function streamRequest(string $url, array $options, ?callable $onChunk = null): PromiseInterface;

    /**
     * Downloads a file asynchronously to a specified destination with cancellation support.
     *
     * @param string $url The URL to download from
     * @param string $destination The file path where the download should be saved
     * @param array<int|string, mixed> $options Request configuration options for downloading.
     *                                           Implementations may accept various options such as:
     *                                           - HTTP headers and authentication
     *                                           - Timeout settings
     *                                           - Progress tracking configuration
     *                                           - Custom implementation-specific options
     *
     * @return PromiseInterface<array{file: string, status: int, headers: array<mixed>, protocol_version: string|null, size: int|false}>
     *         A promise that resolves to an array containing download information
     *         or rejects with HttpStreamException or NetworkException
     */
    public function downloadFile(string $url, string $destination, array $options = []): PromiseInterface;
}
