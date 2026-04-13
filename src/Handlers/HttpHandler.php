<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Handlers;

use Hibla\HttpClient\Handlers\Curl\RequestExecutorHandler;
use Hibla\HttpClient\Handlers\Curl\RetryHandler;
use Hibla\HttpClient\Handlers\Curl\SSEHandler;
use Hibla\HttpClient\Handlers\Curl\StreamingHandler;
use Hibla\HttpClient\Interfaces\Handler\HttpHandlerInterface;
use Hibla\HttpClient\Interfaces\Handler\RequestExecutorHandlerInterface;
use Hibla\HttpClient\Interfaces\Handler\RetryHandlerInterface;
use Hibla\HttpClient\Interfaces\Handler\SSEHandlerInterface;
use Hibla\HttpClient\Interfaces\Handler\StreamingHandlerInterface;
use Hibla\HttpClient\SSE\CancelableSSEPromise;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\ValueObjects\RetryConfig;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Core handler for creating and dispatching asynchronous HTTP requests.
 *
 * This class acts as the workhorse for the Http Api, translating high-level
 * requests into low-level operations managed by the event loop.
 *
 * @internal This class is designed for composing transport handler and should not be used directly.
 */
class HttpHandler implements HttpHandlerInterface
{
    protected StreamingHandlerInterface $streamingHandler;

    protected RequestExecutorHandlerInterface $requestExecutorHandler;

    protected RetryHandlerInterface $retryHandler;

    protected SSEHandlerInterface $sseHandler;

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
     * @inheritDoc
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
     * @inheritDoc
     */
    public function stream(string $url, array $options = [], ?callable $onChunk = null): PromiseInterface
    {
        return $this->streamingHandler->streamRequest($url, $options, $onChunk);
    }

    /**
     * @inheritDoc
     */
    public function download(string $url, string $destination, array $options = [], ?callable $onProgress = null): PromiseInterface
    {
        return $this->streamingHandler->downloadFile($url, $destination, $options, $onProgress);
    }

    /**
     * @inheritDoc
     */
    public function upload(string $url, string $source, array $options = [], ?callable $onProgress = null): PromiseInterface
    {
        return $this->streamingHandler->uploadFile($url, $source, $options, $onProgress);
    }

    /**
     * @inheritDoc
     */
    public function sendRequest(string $url, array $curlOptions, ?RetryConfig $retryConfig = null): PromiseInterface
    {
        if ($retryConfig !== null) {
            return $this->retryHandler->execute($url, $curlOptions, $retryConfig);
        }

        return $this->requestExecutorHandler->execute($url, $curlOptions);
    }
}
