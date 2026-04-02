<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Utilities;

use Hibla\HttpClient\Response;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\Executors\SSERequestExecutor;
use Hibla\HttpClient\Testing\Utilities\Executors\StandardRequestExecutor;
use Hibla\HttpClient\Testing\Utilities\Handlers\ResponseTypeHandler;
use Hibla\HttpClient\Testing\Utilities\Validators\RequestValidator;
use Hibla\HttpClient\ValueObjects\RetryConfig;
use Hibla\Promise\Interfaces\PromiseInterface;

class RequestExecutor
{
    private RequestMatcher $requestMatcher;
    private ResponseFactory $responseFactory;
    private FileManager $fileManager;
    private CookieManager $cookieManager;
    private RequestRecorder $requestRecorder;

    private StandardRequestExecutor $standardExecutor;
    private SSERequestExecutor $sseExecutor;
    private RequestValidator $validator;
    private ResponseTypeHandler $responseTypeHandler;

    public function __construct(
        RequestMatcher $requestMatcher,
        ResponseFactory $responseFactory,
        FileManager $fileManager,
        CookieManager $cookieManager,
        RequestRecorder $requestRecorder,
    ) {
        $this->requestMatcher = $requestMatcher;
        $this->responseFactory = $responseFactory;
        $this->fileManager = $fileManager;
        $this->cookieManager = $cookieManager;
        $this->requestRecorder = $requestRecorder;

        $this->initializeExecutors();
    }

    private function initializeExecutors(): void
    {
        $this->validator = new RequestValidator();
        $this->responseTypeHandler = new ResponseTypeHandler($this->responseFactory, $this->fileManager);

        $this->standardExecutor = new StandardRequestExecutor(
            $this->requestMatcher,
            $this->responseFactory,
            $this->cookieManager,
            $this->requestRecorder,
            $this->validator,
            $this->responseTypeHandler
        );

        $this->sseExecutor = new SSERequestExecutor(
            $this->requestMatcher,
            $this->responseFactory,
            $this->requestRecorder
        );
    }

    /**
     * @param array<int|string, mixed> $curlOptions
     * @param list<MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
     * @return PromiseInterface<Response>
     */
    public function executeSendRequest(
        string $url,
        array $curlOptions,
        array &$mockedRequests,
        array $globalSettings,
        ?RetryConfig $retryConfig = null,
        ?callable $parentSendRequest = null
    ): PromiseInterface {
        return $this->standardExecutor->execute(
            $url,
            $curlOptions,
            $mockedRequests,
            $globalSettings,
            $retryConfig,
            $parentSendRequest
        );
    }

    /**
     * @param array<int, mixed> $curlOptions
     * @param list<MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
     * @param mixed $reconnectConfig
     * @return PromiseInterface<\Hibla\HttpClient\SSE\SSEResponse>
     */
    public function executeSSE(
        string $url,
        array $curlOptions,
        array &$mockedRequests,
        array $globalSettings,
        ?callable $onEvent = null,
        ?callable $onError = null,
        ?callable $parentSSE = null,
        $reconnectConfig = null
    ): PromiseInterface {
        return $this->sseExecutor->execute(
            $url,
            $curlOptions,
            $mockedRequests,
            $globalSettings,
            $onEvent,
            $onError,
            $parentSSE,
            $reconnectConfig
        );
    }
}
