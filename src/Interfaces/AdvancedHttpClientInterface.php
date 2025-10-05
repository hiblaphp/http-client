<?php

namespace Hibla\Http\Interfaces;

use Hibla\Http\CacheConfig;
use Hibla\Http\ProxyConfig;
use Hibla\Http\Response;
use Hibla\Http\RetryConfig;
use Hibla\Http\SSE\SSEReconnectConfig;
use Hibla\Http\SSE\SSEResponse;
use Hibla\Http\StreamingResponse;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;

/**
 * Advanced HTTP client interface with specialized functionality.
 *
 * Extends the basic client builder with advanced features like retries,
 * caching, streaming, downloading, proxy support, and Server-Sent Events.
 */
interface AdvancedHttpClientInterface extends HttpClientBuilderInterface
{
    /**
     * Configure retry behavior with simple parameters.
     */
    public function retry(int $maxRetries = 3, float $baseDelay = 1.0, float $backoffMultiplier = 2.0): self;

    /**
     * Configure retry behavior with a RetryConfig object.
     */
    public function retryWith(RetryConfig $config): self;

    /**
     * Disable retry behavior.
     */
    public function noRetry(): self;

    /**
     * Enable response caching with default settings.
     */
    public function cache(int $ttlSeconds = 3600, bool $respectServerHeaders = true): self;

    /**
     * Configure caching with a CacheConfig object.
     */
    public function cacheWith(CacheConfig $config): self;

    /**
     * Add a single cookie to the request.
     */
    public function withCookie(string $name, string $value): self;

    /**
     * Add multiple cookies to the request.
     *
     * @param array<string, string> $cookies Cookie name-value pairs
     */
    public function withCookies(array $cookies): self;

    /**
     * Enable automatic cookie jar (in-memory).
     */
    public function withCookieJar(): self;

    /**
     * Enable file-based cookie jar with persistence.
     */
    public function withFileCookieJar(string $filename, bool $includeSessionCookies = false): self;

    /**
     * Use a custom cookie jar instance.
     */
    public function useCookieJar(CookieJarInterface $cookieJar): self;

    /**
     * Save all cookies (including session cookies) to a file.
     */
    public function withAllCookiesSaved(string $filename): self;

    /**
     * Clear all cookies from the cookie jar.
     */
    public function clearCookies(): self;

    /**
     * Get the current cookie jar instance.
     */
    public function getCookieJar(): ?CookieJarInterface;

    /**
     * Add a cookie with additional attributes (domain, path, secure, etc.).
     *
     * @param array<string, mixed> $attributes Cookie attributes
     */
    public function cookieWithAttributes(string $name, string $value, array $attributes = []): self;

    /**
     * Configure an HTTP proxy.
     */
    public function withProxy(string $host, int $port, ?string $username = null, ?string $password = null): self;

    /**
     * Configure a SOCKS4 proxy.
     */
    public function withSocks4Proxy(string $host, int $port, ?string $username = null): self;

    /**
     * Configure a SOCKS5 proxy.
     */
    public function withSocks5Proxy(string $host, int $port, ?string $username = null, ?string $password = null): self;

    /**
     * Configure proxy with a ProxyConfig object.
     */
    public function proxyWith(ProxyConfig $config): self;

    /**
     * Disable proxy usage.
     */
    public function noProxy(): self;

    /**
     * Stream a GET request with chunk callbacks.
     *
     * @param (callable(string): void)|null $onChunk Callback invoked for each data chunk
     * @return CancellablePromiseInterface<StreamingResponse>
     */
    public function stream(string $url, ?callable $onChunk = null): CancellablePromiseInterface;

    /**
     * Stream a POST request with chunk callbacks.
     *
     * @param string|resource|array<string, mixed>|null $body Request body
     * @param (callable(string): void)|null $onChunk Callback invoked for each data chunk
     * @return CancellablePromiseInterface<StreamingResponse>
     */
    public function streamPost(string $url, $body = null, ?callable $onChunk = null): CancellablePromiseInterface;

    /**
     * Download a file to a destination path.
     *
     * @return CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>, protocol_version: string|null, size: int|false}> A promise that resolves with download metadata.
     */
    public function download(string $url, string $destination): CancellablePromiseInterface;

    /**
     * Set the data format for SSE events (array, object, or raw).
     */
    public function sseDataFormat(string $format = 'array'): self;

    /**
     * Establish a Server-Sent Events connection.
     *
     * @param (callable(mixed): void)|null $onEvent Callback for each event
     * @param (callable(string): void)|null $onError Callback for connection errors
     * @return CancellablePromiseInterface<SSEResponse>
     */
    public function sse(string $url, ?callable $onEvent = null, ?callable $onError = null, ?SSEReconnectConfig $reconnectConfig = null): CancellablePromiseInterface;

    /**
     * Map/transform SSE event data before invoking callbacks.
     *
     * @param callable(mixed): mixed $mapper Event transformation function
     */
    public function sseMap(callable $mapper): self;

    /**
     * Configure SSE reconnection behavior with simple parameters.
     *
     * @param list<string> $retryableErrors Error message substrings that trigger reconnection
     * @param (callable(int, float, \Throwable): void)|null $onReconnect Callback before each reconnection attempt
     * @param (callable(\Exception): bool)|null $shouldReconnect Custom logic to determine if reconnection should occur
     */
    public function sseReconnect(
        bool $enabled = true,
        int $maxAttempts = 10,
        float $initialDelay = 1.0,
        float $maxDelay = 30.0,
        float $backoffMultiplier = 2.0,
        bool $jitter = true,
        array $retryableErrors = [],
        ?callable $onReconnect = null,
        ?callable $shouldReconnect = null
    ): self;

    /**
     * Configure SSE reconnection with a SSEReconnectConfig object.
     */
    public function sseReconnectWith(SSEReconnectConfig $config): self;

    /**
     * Disable SSE reconnection.
     */
    public function noSseReconnect(): self;

    /**
     * Add a single file to a multipart request.
     *
     * @param string|resource|\Psr\Http\Message\UploadedFileInterface $file
     */
    public function withFile(string $name, $file, ?string $fileName = null, ?string $contentType = null): self;

    /**
     * Add multiple files to a multipart request.
     *
     * @param array<string, string|resource|\Psr\Http\Message\UploadedFileInterface|array{path: string, name?: string, type?: string}> $files Files to upload
     */
    public function withFiles(array $files): self;

    /**
     * Create a multipart request with both data and files.
     *
     * @param array<string, mixed> $data Form data fields
     * @param array<string, string|resource|\Psr\Http\Message\UploadedFileInterface|array{path: string, name?: string, type?: string}> $files Files to upload
     */
    public function multipartWithFiles(array $data = [], array $files = []): self;
}
