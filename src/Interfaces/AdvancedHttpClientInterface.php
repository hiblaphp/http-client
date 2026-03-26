<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces;

use Hibla\HttpClient\ProxyConfig;
use Hibla\HttpClient\RetryConfig;
use Hibla\HttpClient\SSE\SSEBuilder;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\HttpClient\StreamingResponse;
use Hibla\Promise\Interfaces\PromiseInterface;

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
     * Use a custom cookie jar instance.
     */
    public function useCookieJar(CookieJarInterface $cookieJar): self;

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
     * @return PromiseInterface<StreamingResponse>
     */
    public function stream(string $url, ?callable $onChunk = null): PromiseInterface;

    /**
     * Stream a POST request with chunk callbacks.
     *
     * @param string|resource|array<string, mixed>|null $body Request body
     * @param (callable(string): void)|null $onChunk Callback invoked for each data chunk
     * @return PromiseInterface<StreamingResponse>
     */
    public function streamPost(string $url, $body = null, ?callable $onChunk = null): PromiseInterface;

    /**
     * Download a file to a destination path.
     *
     * @return PromiseInterface<array{file: string, status: int, headers: array<mixed>, protocol_version: string|null, size: int|false}> A promise that resolves with download metadata.
     */
    public function download(string $url, string $destination): PromiseInterface;

    /**
     * Creates a fluent SSE builder for this request's transport configuration.
     *
     * All authentication, headers, timeout, and proxy settings already
     * configured on the Request are forwarded automatically.
     *
     * @param string $url The SSE endpoint URL.
     * @return SSEBuilder
     */
    public function sse(string $url): SSEBuilder;

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
