<?php

namespace Hibla\Http\Testing\Utilities;

use Hibla\Http\Response;
use Hibla\Http\RetryConfig;
use Hibla\Http\SSE\SSEReconnectConfig;
use Hibla\Http\SSE\SSEResponse;
use Hibla\Http\StreamingResponse;
use Hibla\Http\Testing\MockedRequest;
use Hibla\Http\Testing\TestingHttpHandler;
use Hibla\Http\Testing\Utilities\Factories\DownloadResponseFactory;
use Hibla\Http\Testing\Utilities\Factories\RetryableResponseFactory;
use Hibla\Http\Testing\Utilities\Factories\SSE\RetryableSSEResponseFactory;
use Hibla\Http\Testing\Utilities\Factories\SSE\SSEResponseFactory;
use Hibla\Http\Testing\Utilities\Factories\StandardResponseFactory;
use Hibla\Http\Testing\Utilities\Factories\StreamingResponseFactory;
use Hibla\Http\Testing\Utilities\Handlers\NetworkSimulationHandler;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

class ResponseFactory
{
    private NetworkSimulationHandler $networkHandler;
    private StandardResponseFactory $standardFactory;
    private RetryableResponseFactory $retryableFactory;
    private StreamingResponseFactory $streamingFactory;
    private DownloadResponseFactory $downloadFactory;
    private SSEResponseFactory $sseFactory;
    private RetryableSSEResponseFactory $retryableSSEFactory;

    public function __construct(
        NetworkSimulator $networkSimulator,
        ?TestingHttpHandler $handler = null
    ) {
        $this->networkHandler = new NetworkSimulationHandler($networkSimulator, $handler);
        
        $this->standardFactory = new StandardResponseFactory($this->networkHandler);
        $this->retryableFactory = new RetryableResponseFactory($this->networkHandler);
        $this->streamingFactory = new StreamingResponseFactory($this->networkHandler);
        $this->downloadFactory = new DownloadResponseFactory($this->networkHandler);
        $this->sseFactory = new SSEResponseFactory($this->networkHandler);
        $this->retryableSSEFactory = new RetryableSSEResponseFactory($this->networkHandler);
    }

    /**
     * @return PromiseInterface<Response>
     */
    public function createMockedResponse(MockedRequest $mock): PromiseInterface
    {
        return $this->standardFactory->create($mock);
    }

    /**
     * @return PromiseInterface<Response>
     */
    public function createRetryableMockedResponse(
        RetryConfig $retryConfig,
        callable $mockProvider
    ): PromiseInterface {
        return $this->retryableFactory->create($retryConfig, $mockProvider);
    }

    /**
     * @return CancellablePromiseInterface<StreamingResponse>
     */
    public function createMockedStream(
        MockedRequest $mock,
        ?callable $onChunk,
        callable $createStream
    ): CancellablePromiseInterface {
        return $this->streamingFactory->create($mock, $onChunk, $createStream);
    }

    /**
     * @return CancellablePromiseInterface<array{file: string, status: int, headers: array<string, string>, size: int, protocol_version: string}>
     */
    public function createMockedDownload(
        MockedRequest $mock,
        string $destination,
        FileManager $fileManager
    ): CancellablePromiseInterface {
        return $this->downloadFactory->create($mock, $destination, $fileManager);
    }

    /**
     * @return CancellablePromiseInterface<SSEResponse>
     */
    public function createMockedSSE(
        MockedRequest $mock,
        ?callable $onEvent,
        ?callable $onError
    ): CancellablePromiseInterface {
        return $this->sseFactory->create($mock, $onEvent, $onError);
    }

    /**
     * @return CancellablePromiseInterface<SSEResponse>
     */
    public function createRetryableMockedSSE(
        SSEReconnectConfig $reconnectConfig,
        callable $mockProvider,
        ?callable $onEvent,
        ?callable $onError,
        ?callable $onReconnect = null
    ): CancellablePromiseInterface {
        return $this->retryableSSEFactory->create(
            $reconnectConfig,
            $mockProvider,
            $onEvent,
            $onError,
            $onReconnect
        );
    }
}