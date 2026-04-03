<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Utilities;

use Hibla\HttpClient\Response;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\HttpClient\StreamingResponse;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\TestingHttpHandler;
use Hibla\HttpClient\Testing\Utilities\Factories\DownloadResponseFactory;
use Hibla\HttpClient\Testing\Utilities\Factories\RetryableResponseFactory;
use Hibla\HttpClient\Testing\Utilities\Factories\SSE\RetryableSSEResponseFactory;
use Hibla\HttpClient\Testing\Utilities\Factories\SSE\SSEResponseFactory;
use Hibla\HttpClient\Testing\Utilities\Factories\StandardResponseFactory;
use Hibla\HttpClient\Testing\Utilities\Factories\StreamingResponseFactory;
use Hibla\HttpClient\Testing\Utilities\Factories\UploadResponseFactory;
use Hibla\HttpClient\Testing\Utilities\Handlers\NetworkSimulationHandler;
use Hibla\HttpClient\ValueObjects\RetryConfig;
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
    private UploadResponseFactory $uploadFactory;

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
        $this->uploadFactory = new UploadResponseFactory($this->networkHandler);
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
     * @return PromiseInterface<StreamingResponse>
     */
    public function createMockedStream(
        MockedRequest $mock,
        ?callable $onChunk,
        callable $createStream
    ): PromiseInterface {
        return $this->streamingFactory->create($mock, $onChunk, $createStream);
    }

    /**
     * @return PromiseInterface<array{file: string, status: int, headers: array<string, string>, size: int, protocol_version: string}>
     */
    public function createMockedDownload(
        MockedRequest $mock,
        string $destination,
        FileManager $fileManager,
        ?callable $onProgress = null
    ): PromiseInterface {
        return $this->downloadFactory->create($mock, $destination, $fileManager, $onProgress);
    }

    public function createMockedUpload(
        MockedRequest $mock,
        string $source,
        string $url,
        ?callable $onProgress = null
    ): PromiseInterface {
        return $this->uploadFactory->create($mock, $source, $url, $onProgress);
    }

    /**
     * @return PromiseInterface<SSEResponse>
     */
    public function createMockedSSE(
        MockedRequest $mock,
        ?callable $onEvent,
        ?callable $onError
    ): PromiseInterface {
        return $this->sseFactory->create($mock, $onEvent, $onError);
    }

    /**
     * @return PromiseInterface<SSEResponse>
     */
    public function createRetryableMockedSSE(
        SSEReconnectConfig $reconnectConfig,
        callable $mockProvider,
        ?callable $onEvent,
        ?callable $onError,
        ?callable $onReconnect = null
    ): PromiseInterface {
        return $this->retryableSSEFactory->create(
            $reconnectConfig,
            $mockProvider,
            $onEvent,
            $onError,
            $onReconnect
        );
    }
}
