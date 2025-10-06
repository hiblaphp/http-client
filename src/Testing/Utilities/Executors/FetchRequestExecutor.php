<?php

namespace Hibla\Http\Testing\Utilities\Executors;

use Hibla\Http\Response;
use Hibla\Http\StreamingResponse;
use Hibla\Http\Testing\Exceptions\UnexpectedRequestException;
use Hibla\Http\Testing\MockedRequest;
use Hibla\Http\Testing\Utilities\FileManager;
use Hibla\Http\Testing\Utilities\Handlers\CacheHandler;
use Hibla\Http\Testing\Utilities\Handlers\ResponseTypeHandler;
use Hibla\Http\Testing\Utilities\RequestMatcher;
use Hibla\Http\Testing\Utilities\RequestRecorder;
use Hibla\Http\Testing\Utilities\ResponseFactory;
use Hibla\Http\Testing\Utilities\Validators\RequestValidator;
use Hibla\Http\Traits\FetchOptionTrait;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

class FetchRequestExecutor
{
    use FetchOptionTrait;

    private RequestMatcher $requestMatcher;
    private ResponseFactory $responseFactory;
    private FileManager $fileManager;
    private RequestRecorder $requestRecorder;
    private CacheHandler $cacheHandler;
    private RequestValidator $validator;
    private ResponseTypeHandler $responseTypeHandler;
    private RetryableRequestExecutor $retryExecutor;

    public function __construct(
        RequestMatcher $requestMatcher,
        ResponseFactory $responseFactory,
        FileManager $fileManager,
        RequestRecorder $requestRecorder,
        CacheHandler $cacheHandler,
        RequestValidator $validator
    ) {
        $this->requestMatcher = $requestMatcher;
        $this->responseFactory = $responseFactory;
        $this->fileManager = $fileManager;
        $this->requestRecorder = $requestRecorder;
        $this->cacheHandler = $cacheHandler;
        $this->validator = $validator;

        $this->responseTypeHandler = new ResponseTypeHandler(
            $responseFactory,
            $fileManager,
            $cacheHandler
        );

        $this->retryExecutor = new RetryableRequestExecutor(
            $requestMatcher,
            $responseFactory,
            $requestRecorder
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param list<MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
     * @return CancellablePromiseInterface<array<string, mixed>|StreamingResponse>|PromiseInterface<Response>
     */
    public function execute(
        string $url,
        array $options,
        array &$mockedRequests,
        array $globalSettings,
        ?callable $parentFetch = null,
        ?callable $createStream = null
    ): PromiseInterface|CancellablePromiseInterface {
        $method = $this->extractMethod($options);
        $curlOptions = $this->normalizeFetchOptions($url, $options);
        /** @var array<int, mixed> $curlOnlyOptions */
        $curlOnlyOptions = array_filter($curlOptions, 'is_int', ARRAY_FILTER_USE_KEY);

        $retryConfig = $this->extractRetryConfig($options);
        $cacheConfig = $this->extractCacheConfig($options);

        // Handle SSE requests
        if ($this->validator->isSSERequested($options)) {
            return $this->handleSSERequest($url, $options, $method, $curlOnlyOptions, $mockedRequests);
        }

        // Try cache
        if ($this->cacheHandler->tryServeFromCache($url, $method, $cacheConfig)) {
            return Promise::resolved($this->cacheHandler->getCachedResponse($url, $cacheConfig));
        }

        // Handle retry
        if ($retryConfig !== null) {
            return $this->retryExecutor->executeWithMockRetry(
                $url,
                $options,
                $retryConfig,
                $method,
                $mockedRequests,
                $createStream,
                $this->fileManager
            );
        }

        // Standard execution
        return $this->executeStandard(
            $url,
            $options,
            $method,
            $curlOnlyOptions,
            $mockedRequests,
            $globalSettings,
            $cacheConfig,
            $parentFetch,
            $createStream
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function extractMethod(array $options): string
    {
        $methodValue = $options['method'] ?? 'GET';
        return is_string($methodValue) ? strtoupper($methodValue) : 'GET';
    }

    /**
     * @param array<string, mixed> $options
     * @param array<int, mixed> $curlOnlyOptions
     * @param list<MockedRequest> $mockedRequests
     * @return CancellablePromiseInterface<\Hibla\Http\SSE\SSEResponse>
     */
    private function handleSSERequest(
        string $url,
        array $options,
        string $method,
        array $curlOnlyOptions,
        array &$mockedRequests
    ): CancellablePromiseInterface {
        $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOnlyOptions);

        if ($match !== null) {
            $mock = $match['mock'];
            if (!$mock->isPersistent()) {
                array_splice($mockedRequests, $match['index'], 1);
            }

            if ($mock->isSSE()) {
                $onEvent = $options['on_event'] ?? $options['onEvent'] ?? null;
                $onError = $options['on_error'] ?? $options['onError'] ?? null;

                // @phpstan-ignore-next-line
                return $this->responseFactory->createMockedSSE(
                    $mock,
                    is_callable($onEvent) ? $onEvent : null,
                    is_callable($onError) ? $onError : null
                );
            }
        }

        throw new \RuntimeException('SSE request matched but mock is not configured for SSE');
    }

    /**
     * @param array<string, mixed> $options
     * @param array<int, mixed> $curlOnlyOptions
     * @param list<MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
     * @return PromiseInterface<Response>|CancellablePromiseInterface<StreamingResponse|array<string, mixed>>
     */
    private function executeStandard(
        string $url,
        array $options,
        string $method,
        array $curlOnlyOptions,
        array &$mockedRequests,
        array $globalSettings,
        $cacheConfig,
        ?callable $parentFetch,
        ?callable $createStream
    ): PromiseInterface|CancellablePromiseInterface {
        $this->requestRecorder->recordRequest($method, $url, $curlOnlyOptions);

        $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOnlyOptions);

        if ($match !== null) {
            return $this->responseTypeHandler->handleMockedResponse(
                $match,
                $options,
                $mockedRequests,
                $cacheConfig,
                $url,
                $method,
                $createStream
            );
        }

        return $this->handleNoMatch(
            $method,
            $url,
            $curlOnlyOptions,
            $mockedRequests,
            $globalSettings,
            $parentFetch,
            $options
        );
    }

    /**
     * @param array<int, mixed> $curlOnlyOptions
     * @param list<MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
     * @param array<string, mixed> $options
     * @return PromiseInterface<Response>|CancellablePromiseInterface<StreamingResponse|array<string, mixed>>
     */
    private function handleNoMatch(
        string $method,
        string $url,
        array $curlOnlyOptions,
        array $mockedRequests,
        array $globalSettings,
        ?callable $parentFetch,
        array $options
    ): PromiseInterface|CancellablePromiseInterface {
        if ((bool)($globalSettings['strict_matching'] ?? true)) {
            throw UnexpectedRequestException::noMatchFound($method, $url, $curlOnlyOptions, $mockedRequests);
        }

        if (!(bool)($globalSettings['allow_passthrough'] ?? false)) {
            throw UnexpectedRequestException::noMatchFound($method, $url, $curlOnlyOptions, $mockedRequests);
        }

        if ($parentFetch === null) {
            throw new \RuntimeException('No parent fetch available');
        }

        /** @var PromiseInterface<Response>|CancellablePromiseInterface<StreamingResponse|array<string, mixed>> $result */
        $result = $parentFetch($url, $options);
        return $result;
    }
}
