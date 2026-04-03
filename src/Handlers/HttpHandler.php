<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Handlers;

use Hibla\HttpClient\Handlers\Curl\RequestExecutorHandler;
use Hibla\HttpClient\Handlers\Curl\RetryHandler;
use Hibla\HttpClient\Handlers\Curl\SSEHandler;
use Hibla\HttpClient\Handlers\Curl\StreamingHandler;
use Hibla\HttpClient\Interfaces\Cookie\CookieJarInterface;
use Hibla\HttpClient\Interfaces\Handler\RequestExecutorHandlerInterface;
use Hibla\HttpClient\Interfaces\Handler\RetryHandlerInterface;
use Hibla\HttpClient\Interfaces\Handler\SSEHandlerInterface;
use Hibla\HttpClient\Interfaces\Handler\StreamingHandlerInterface;
use Hibla\HttpClient\Interfaces\ResponseInterface;
use Hibla\HttpClient\Interfaces\SSEResponseInterface;
use Hibla\HttpClient\Interfaces\StreamingResponseInterface;
use Hibla\HttpClient\SSE\CancelableSSEPromise;
use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\ValueObjects\DownloadProgress;
use Hibla\HttpClient\ValueObjects\RetryConfig;
use Hibla\HttpClient\ValueObjects\UploadProgress;
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
    protected StreamingHandlerInterface $streamingHandler;
    protected RequestExecutorHandlerInterface $requestExecutorHandler;
    protected RetryHandlerInterface $retryHandler;
    protected SSEHandlerInterface $sseHandler;
    protected ?CookieJarInterface $defaultCookieJar = null;

    /**
     * Creates a new HttpHandler instance.
     */
    public function __construct(
        ?StreamingHandlerInterface $streamingHandler = null,
        ?RequestExecutorHandlerInterface $requestExecutor = null,
        ?RetryHandlerInterface $retryHandler = null,
        ?SSEHandlerInterface $sseHandler = null
    ) {
        $this->streamingHandler = $streamingHandler ?? new StreamingHandler();
        $this->requestExecutorHandler = $requestExecutor ?? new RequestExecutorHandler();
        $this->retryHandler = $retryHandler ?? new RetryHandler();
        $this->sseHandler = $sseHandler ?? new SSEHandler();
    }

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
    ): PromiseInterface {
        $innerPromise = $this->sseHandler->connect($url, $options, $onEvent, $onError, $reconnectConfig);

        return new CancelableSSEPromise($innerPromise);
    }

    /**
     * Streams an HTTP response, processing it in chunks.
     *
     * Ideal for large responses that should not be fully loaded into memory.
     *
     * @param  string  $url  The URL to stream from.
     * @param  array<int|string, mixed>  $options  Request options for internal use and testing extensions.
     * @param  callable(string): void|null  $onChunk  An optional callback to execute for each received data chunk.
     * @return PromiseInterface<StreamingResponseInterface>
     *
     * @internal This method is designed for extension by TestingHttpHandler. The $options parameter
     *           allows testing implementations to intercept and mock requests. End users should use
     *           $http->request()->stream() for configuration instead.
     */
    public function stream(string $url, array $options = [], ?callable $onChunk = null): PromiseInterface
    {
        return $this->streamingHandler->streamRequest($url, $options, $onChunk);
    }

    /**
     * Asynchronously downloads a file from a URL to a specified destination.
     *
     * @param  string  $url  The URL of the file to download.
     * @param  string  $destination  The local path to save the file.
     * @param  array<int|string, mixed>  $options  Request options for internal use and testing extensions.
     * @param  (callable(DownloadProgress): void)|null $onProgress
     * @return PromiseInterface<array{file: string, status: int, headers: array<mixed>, protocol_version: string|null, size: int|false}>
     *
     * @internal This method is designed for extension by TestingHttpHandler.
     */
    public function download(string $url, string $destination, array $options = [], ?callable $onProgress = null): PromiseInterface
    {
        return $this->streamingHandler->downloadFile($url, $destination, $options, $onProgress);
    }

    /**
     * Asynchronously uploads a file from a local path to a URL.
     *
     * @param  string  $url  The URL to upload the file to.
     * @param  string  $source  The local path of the file to upload.
     * @param  array<int|string, mixed>  $options  Request options for internal use and testing extensions.
     * @param  (callable(UploadProgress): void)|null  $onProgress
     * @return PromiseInterface<array{url: string, status: int, headers: array<mixed>, protocol_version: string|null}>
     *
     * @internal This method is designed for extension by TestingHttpHandler.
     */
    public function upload(string $url, string $source, array $options = [], ?callable $onProgress = null): PromiseInterface
    {
        return $this->streamingHandler->uploadFile($url, $source, $options, $onProgress);
    }

    /**
     * The main entry point for sending a request from the Request builder.
     * It intelligently applies caching and retry logic before dispatching the request.
     *
     * TestingHttpHandler overrides this method to intercept requests and return mocked responses.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $curlOptions  cURL options for the request.
     * @param  RetryConfig|null  $retryConfig  Optional retry configuration.
     * @return PromiseInterface<ResponseInterface>
     *
     * @internal This method is the primary extension point for TestingHttpHandler. It is called by
     *           the Request builder and can be overridden to intercept all requests made through
     *           the fluent Request API.
     */
    public function sendRequest(string $url, array $curlOptions, ?RetryConfig $retryConfig = null): PromiseInterface
    {
        if ($retryConfig !== null) {
            return $this->retryHandler->execute($url, $curlOptions, $retryConfig);
        }

        return $this->requestExecutorHandler->execute($url, $curlOptions);
    }
}
