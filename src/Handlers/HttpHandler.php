<?php

namespace Hibla\HttpClient\Handlers;

use Hibla\HttpClient\CacheConfig;
use Hibla\HttpClient\Exceptions\HttpStreamException;
use Hibla\HttpClient\Interfaces\CookieJarInterface;
use Hibla\HttpClient\Request;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\RetryConfig;
use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\HttpClient\Stream;
use Hibla\HttpClient\StreamingResponse;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Core handler for creating and dispatching asynchronous HTTP requests.
 *
 * This class acts as the workhorse for the Http Api, translating high-level
 * requests into low-level operations managed by the event loop.
 *
 * Most methods are marked as @internal and are designed to be overridden
 * by testing implementations like TestingHttpHandler.
 */
class HttpHandler
{
    protected StreamingHandler $streamingHandler;
    protected FetchHandler $fetchHandler;
    protected RequestExecutorHandler $requestExecutorHandler;
    protected RetryHandler $retryHandler;
    protected CacheHandler $cacheHandler;
    protected SSEHandler $sseHandler;
    protected ?CookieJarInterface $defaultCookieJar = null;

    /**
     * Creates a new HttpHandler instance.
     */
    public function __construct(
        ?StreamingHandler $streamingHandler = null,
        ?FetchHandler $fetchHandler = null,
        ?RequestExecutorHandler $requestExecutor = null,
        ?RetryHandler $retryHandler = null,
        ?CacheHandler $cacheHandler = null,
        ?SSEHandler $sseHandler = null
    ) {
        $this->streamingHandler = $streamingHandler ?? new StreamingHandler();
        $this->requestExecutorHandler = $requestExecutor ?? new RequestExecutorHandler();
        $this->retryHandler = $retryHandler ?? new RetryHandler();
        $this->cacheHandler = $cacheHandler ?? new CacheHandler($this->requestExecutorHandler, $this->retryHandler);
        $this->fetchHandler = $fetchHandler ?? new FetchHandler($this->streamingHandler);
        $this->sseHandler = $sseHandler ?? new SSEHandler();
    }

    /**
     * Creates a new fluent HTTP request builder instance.
     *
     * @return Request The request builder.
     */
    public function request(): Request
    {
        return new Request($this);
    }

    /**
     * Creates an SSE (Server-Sent Events) connection with optional reconnection.
     *
     * @param  string  $url  The SSE endpoint URL
     * @param  array<int|string, mixed>  $options  Request options
     * @param  callable(SSEEvent): void|null  $onEvent  Optional callback for each SSE event
     * @param  callable(string): void|null  $onError  Optional callback for connection errors
     * @param  SSEReconnectConfig|null  $reconnectConfig  Optional reconnection configuration
     * @return CancellablePromiseInterface<SSEResponse>
     *
     * @internal This method is designed for extension by TestingHttpHandler and internal use.
     */
    public function sse(
        string $url,
        array $options = [],
        ?callable $onEvent = null,
        ?callable $onError = null,
        ?SSEReconnectConfig $reconnectConfig = null
    ): CancellablePromiseInterface {
        $curlOptions = $this->normalizeFetchOptions($url, $options, true);

        /** @var array<int, mixed> $curlOnlyOptions */
        $curlOnlyOptions = array_filter($curlOptions, 'is_int', ARRAY_FILTER_USE_KEY);

        return $this->sseHandler->connect($url, $curlOnlyOptions, $onEvent, $onError, $reconnectConfig);
    }

    /**
     * Streams an HTTP response, processing it in chunks.
     *
     * The $options parameter allows TestingHttpHandler to override this method
     * and provide mocked streaming responses.
     *
     * Ideal for large responses that should not be fully loaded into memory.
     *
     * @param  string  $url  The URL to stream from.
     * @param  array<int|string, mixed>  $options  Request options for internal use and testing extensions.
     * @param  callable(string): void|null  $onChunk  An optional callback to execute for each received data chunk.
     * @return CancellablePromiseInterface<StreamingResponse> A promise that resolves with a StreamingResponse object.
     *
     * @internal This method is designed for extension by TestingHttpHandler. The $options parameter
     *           allows testing implementations to intercept and mock requests. End users should use
     *           $http->request()->stream() for configuration instead.
     */
    public function stream(string $url, array $options = [], ?callable $onChunk = null): CancellablePromiseInterface
    {
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        /** @var array<int, mixed> $curlOnlyOptions */
        $curlOnlyOptions = array_filter($curlOptions, 'is_int', ARRAY_FILTER_USE_KEY);

        return $this->streamingHandler->streamRequest($url, $curlOnlyOptions, $onChunk);
    }

    /**
     * Asynchronously downloads a file from a URL to a specified destination.
     *
     * @param  string  $url  The URL of the file to download.
     * @param  string  $destination  The local path to save the file.
     * @param  array<int|string, mixed>  $options  Request options for internal use and testing extensions.
     * @return CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>, protocol_version: string|null, size: int|false}> A promise that resolves with download metadata.
     *
     * @internal This method is designed for extension by TestingHttpHandler.
     */
    public function download(string $url, string $destination, array $options = []): CancellablePromiseInterface
    {
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        /** @var array<int, mixed> $curlOnlyOptions */
        $curlOnlyOptions = array_filter($curlOptions, 'is_int', ARRAY_FILTER_USE_KEY);

        return $this->streamingHandler->downloadFile($url, $destination, $curlOnlyOptions);
    }

    /**
     * Creates a new stream from a string.
     *
     * @param  string  $content  The initial content of the stream.
     * @return Stream A new Stream object.
     *
     * @throws HttpStreamException If temporary stream creation fails.
     *
     * @internal This method is designed for extension by TestingHttpHandler for stream mocking.
     */
    public function createStream(string $content = ''): Stream
    {
        $resource = fopen('php://temp', 'w+b');
        if ($resource === false) {
            throw new HttpStreamException('Failed to create temporary stream');
        }

        if ($content !== '') {
            fwrite($resource, $content);
            rewind($resource);
        }

        return new Stream($resource);
    }

    /**
     * The main entry point for sending a request from the Request builder.
     * It intelligently applies caching and retry logic before dispatching the request.
     *
     * TestingHttpHandler overrides this method to intercept requests and return mocked responses.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $curlOptions  cURL options for the request.
     * @param  CacheConfig|null  $cacheConfig  Optional cache configuration.
     * @param  RetryConfig|null  $retryConfig  Optional retry configuration.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     *
     * @internal This method is the primary extension point for TestingHttpHandler. It is called by
     *           the Request builder and can be overridden to intercept all requests made through
     *           the fluent Request API.
     */
    public function sendRequest(string $url, array $curlOptions, ?CacheConfig $cacheConfig = null, ?RetryConfig $retryConfig = null): PromiseInterface
    {
        if ($cacheConfig !== null && ($curlOptions[CURLOPT_CUSTOMREQUEST] ?? 'GET') === 'GET') {
            return $this->cacheHandler->execute($url, $curlOptions, $cacheConfig, $retryConfig);
        }

        if ($retryConfig !== null) {
            return $this->retryHandler->execute($url, $curlOptions, $retryConfig);
        }

        return $this->requestExecutorHandler->execute($url, $curlOptions);
    }

    /**
     * A flexible, fetch-like method for making HTTP requests with streaming support.
     * This method delegates to the FetchHandler for implementation.
     *
     * TestingHttpHandler overrides this to provide comprehensive request mocking.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  An associative array of request options.
     * @return PromiseInterface<Response>|CancellablePromiseInterface<StreamingResponse>|CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>, protocol_version: string|null, size: int|false}>|CancellablePromiseInterface<SSEResponse> A promise that resolves with a Response, StreamingResponse, download metadata, or SSEResponse.
     *
     * @internal This method is a key extension point for TestingHttpHandler. It handles fetch-style
     *           requests and can return different response types based on options (streaming, downloads, etc.).
     */
    public function fetch(string $url, array $options = []): PromiseInterface|CancellablePromiseInterface
    {
        return $this->fetchHandler->fetch($url, $options);
    }

    /**
     * Get the default cookie jar.
     *
     * @internal This method is for internal cookie jar access and may be used by extensions.
     */
    public function getCookieJar(): ?CookieJarInterface
    {
        return $this->defaultCookieJar;
    }

    /**
     * Normalizes fetch options from various formats to cURL options.
     * This method delegates to the FetchHandler for implementation.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  The options to normalize.
     * @param  bool  $ensureSSEHeaders  Whether to ensure SSE-specific headers are set.
     * @return array<int|string, mixed> Normalized cURL options.
     *
     * @internal This method converts user-friendly options to cURL options. TestingHttpHandler
     *           may use this to understand request configuration before mocking.
     */
    protected function normalizeFetchOptions(string $url, array $options, bool $ensureSSEHeaders = false): array
    {
        return $this->fetchHandler->normalizeFetchOptions($url, $options, $ensureSSEHeaders);
    }
}
