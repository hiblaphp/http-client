<?php

namespace Hibla\HttpClient\Testing\Utilities;

use Hibla\HttpClient\CacheConfig;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\RetryConfig;
use Hibla\HttpClient\StreamingResponse;
use Hibla\HttpClient\Testing\Utilities\Executors\FetchRequestExecutor;
use Hibla\HttpClient\Testing\Utilities\Executors\SSERequestExecutor;
use Hibla\HttpClient\Testing\Utilities\Executors\StandardRequestExecutor;
use Hibla\HttpClient\Testing\Utilities\Handlers\CacheHandler;
use Hibla\HttpClient\Testing\Utilities\Validators\RequestValidator;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Traits\FetchOptionTrait;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

class RequestExecutor
{
    use FetchOptionTrait;

    private RequestMatcher $requestMatcher;
    private ResponseFactory $responseFactory;
    private FileManager $fileManager;
    private CookieManager $cookieManager;
    private RequestRecorder $requestRecorder;
    private CacheManager $cacheManager;

    private StandardRequestExecutor $standardExecutor;
    private SSERequestExecutor $sseExecutor;
    private FetchRequestExecutor $fetchExecutor;
    private CacheHandler $cacheHandler;
    private RequestValidator $validator;

    public function __construct(
        RequestMatcher $requestMatcher,
        ResponseFactory $responseFactory,
        FileManager $fileManager,
        CookieManager $cookieManager,
        RequestRecorder $requestRecorder,
        CacheManager $cacheManager
    ) {
        $this->requestMatcher = $requestMatcher;
        $this->responseFactory = $responseFactory;
        $this->fileManager = $fileManager;
        $this->cookieManager = $cookieManager;
        $this->requestRecorder = $requestRecorder;
        $this->cacheManager = $cacheManager;

        $this->initializeExecutors();
    }

    private function initializeExecutors(): void
    {
        $this->cacheHandler = new CacheHandler($this->cacheManager, $this->requestRecorder);
        $this->validator = new RequestValidator();

        $this->standardExecutor = new StandardRequestExecutor(
            $this->requestMatcher,
            $this->responseFactory,
            $this->cookieManager,
            $this->requestRecorder,
            $this->cacheHandler,
            $this->validator
        );

        $this->sseExecutor = new SSERequestExecutor(
            $this->requestMatcher,
            $this->responseFactory,
            $this->requestRecorder
        );

        $this->fetchExecutor = new FetchRequestExecutor(
            $this->requestMatcher,
            $this->responseFactory,
            $this->fileManager,
            $this->requestRecorder,
            $this->cacheHandler,
            $this->validator
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
        ?CacheConfig $cacheConfig = null,
        ?RetryConfig $retryConfig = null,
        ?callable $parentSendRequest = null
    ): PromiseInterface {
        return $this->standardExecutor->execute(
            $url,
            $curlOptions,
            $mockedRequests,
            $globalSettings,
            $cacheConfig,
            $retryConfig,
            $parentSendRequest
        );
    }

    /**
     * @param array<int, mixed> $curlOptions
     * @param list<MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
     * @param mixed $reconnectConfig
     * @return CancellablePromiseInterface<\Hibla\HttpClient\SSE\SSEResponse>
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
    ): CancellablePromiseInterface {
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

    /**
     * @param array<string, mixed> $options
     * @param list<MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
     * @return CancellablePromiseInterface<array<string, mixed>|StreamingResponse>|PromiseInterface<Response>
     */
    public function executeFetch(
        string $url,
        array $options,
        array &$mockedRequests,
        array $globalSettings,
        ?callable $parentFetch = null,
        ?callable $createStream = null
    ): PromiseInterface|CancellablePromiseInterface {
        /** @var CancellablePromiseInterface<array<string, mixed>|StreamingResponse>|PromiseInterface<Response> */
        return $this->fetchExecutor->execute(
            $url,
            $options,
            $mockedRequests,
            $globalSettings,
            $parentFetch,
            $createStream
        );
    }
}
