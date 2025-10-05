<?php

namespace Hibla\Http\Testing\Utilities;

use Hibla\Http\CacheConfig;
use Hibla\Http\Handlers\HttpHandler;
use Hibla\Http\Response;
use Hibla\Http\RetryConfig;
use Hibla\Http\StreamingResponse;
use Hibla\Http\Testing\Exceptions\MockAssertionException;
use Hibla\Http\Testing\Exceptions\MockException;
use Hibla\Http\Testing\Exceptions\UnexpectedRequestException;
use Hibla\Http\Testing\MockedRequest;
use Hibla\Http\Traits\FetchOptionTrait;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Psr\Http\Message\StreamInterface;

class RequestExecutor
{
    use FetchOptionTrait;

    private RequestMatcher $requestMatcher;
    private ResponseFactory $responseFactory;
    private FileManager $fileManager;
    private CookieManager $cookieManager;
    private RequestRecorder $requestRecorder;
    private CacheManager $cacheManager;

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
        /** @var array<int, mixed> $curlOnlyOptions */
        $curlOnlyOptions = array_filter($curlOptions, 'is_int', ARRAY_FILTER_USE_KEY);

        if ($this->isSSERequest($curlOnlyOptions)) {
            throw new \InvalidArgumentException(
                'SSE requests should use $http->request()->sse() or $http->sse() directly, not send() or get()/post() methods'
            );
        }

        $this->cookieManager->applyCookiesForRequestOptions($curlOptions, $url);

        $method = is_string($curlOptions[CURLOPT_CUSTOMREQUEST] ?? null)
            ? $curlOptions[CURLOPT_CUSTOMREQUEST]
            : 'GET';

        $matchedMock = $this->requestMatcher->findMatchingMock(
            $mockedRequests,
            $method,
            $url,
            $curlOnlyOptions
        );

        if ($matchedMock === null) {
            if ((bool)($globalSettings['throw_on_unexpected'] ?? true)) {
                throw UnexpectedRequestException::noMatchFound(
                    $method,
                    $url,
                    $curlOnlyOptions,
                    $mockedRequests
                );
            }

            if ((bool)($globalSettings['allow_passthrough'] ?? false)) {
                if ($parentSendRequest === null) {
                    throw new \RuntimeException('No parent send request handler available');
                }
                /** @var PromiseInterface<Response> $result */
                $result = $parentSendRequest($url, $curlOptions, $cacheConfig, $retryConfig);

                return $result;
            }

            throw UnexpectedRequestException::noMatchFound(
                $method,
                $url,
                $curlOnlyOptions,
                $mockedRequests
            );
        }

        if ($cacheConfig !== null && $this->tryServeFromCache($url, $method, $cacheConfig)) {
            $cachedResponse = $this->cacheManager->getCachedResponse($url, $cacheConfig);
            if ($cachedResponse === null) {
                throw new \RuntimeException('Cache indicated response available but returned null');
            }

            return Promise::resolved($cachedResponse);
        }

        /** @var PromiseInterface<Response> $promise */
        $promise = $this->executeMockedRequest(
            $url,
            $curlOptions,
            $mockedRequests,
            $globalSettings,
            $retryConfig,
            $parentSendRequest
        );

        return $promise->then(function (Response $response) use ($curlOptions, $url, $cacheConfig, $method) {
            $rawHeaders = $response->getHeaders();

            // Transform headers: ensure each value is an array with string key
            $transformedHeaders = [];
            foreach ($rawHeaders as $key => $value) {
                if (is_string($key)) {
                    $transformedHeaders[$key] = is_array($value) ? $value : [$value];
                }
            }

            $this->cookieManager->processResponseCookiesForOptions($transformedHeaders, $curlOptions, $url);

            if ($cacheConfig !== null && $method === 'GET' && $response->ok()) {
                $this->cacheManager->cacheResponse($url, $response, $cacheConfig);
            }

            return $response;
        });
    }

    /**
     * @param array<int, mixed> $curlOptions
     * @param list<MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
     * @param mixed $reconnectConfig
     * @return CancellablePromiseInterface<\Hibla\Http\SSE\SSEResponse>
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
        $method = 'GET';

        if ($reconnectConfig instanceof \Hibla\Http\SSE\SSEReconnectConfig && $reconnectConfig->enabled) {
            return $this->executeSSEWithRetry(
                $url,
                $curlOptions,
                $mockedRequests,
                $globalSettings,
                $onEvent,
                $onError,
                $reconnectConfig,
                $parentSSE
            );
        }

        $this->requestRecorder->recordRequest($method, $url, $curlOptions);

        $match = $this->requestMatcher->findMatchingMock(
            $mockedRequests,
            $method,
            $url,
            $curlOptions
        );

        if ($match !== null) {
            $mock = $match['mock'];

            if (! $mock->isPersistent()) {
                array_splice($mockedRequests, $match['index'], 1);
            }

            if ($mock->isSSE()) {
                return $this->responseFactory->createMockedSSE($mock, $onEvent, $onError);
            }

            throw new \RuntimeException(
                'Mock matched for SSE request but is not configured as SSE. ' .
                    'Use ->respondWithSSE() instead of ->respondWith() or ->respondJson()'
            );
        }

        if ((bool)($globalSettings['strict_matching'] ?? true)) {
            throw UnexpectedRequestException::noMatchFound(
                $method,
                $url,
                $curlOptions,
                $mockedRequests
            );
        }

        if (! (bool)($globalSettings['allow_passthrough'] ?? false)) {
            throw UnexpectedRequestException::noMatchFound(
                $method,
                $url,
                $curlOptions,
                $mockedRequests
            );
        }

        if ($parentSSE === null) {
            throw new \RuntimeException('No parent SSE handler available');
        }

        /** @var CancellablePromiseInterface<\Hibla\Http\SSE\SSEResponse> $result */
        $result = $parentSSE($url, [], $onEvent, $onError, $reconnectConfig);

        return $result;
    }

    /**
     * @param array<int, mixed> $curlOptions
     * @param list<MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
     * @param \Hibla\Http\SSE\SSEReconnectConfig $reconnectConfig
     * @return CancellablePromiseInterface<\Hibla\Http\SSE\SSEResponse>
     */
    private function executeSSEWithRetry(
        string $url,
        array $curlOptions,
        array &$mockedRequests,
        array $globalSettings,
        ?callable $onEvent,
        ?callable $onError,
        $reconnectConfig,
        ?callable $parentSSE
    ): CancellablePromiseInterface {
        $method = 'GET';

        $mockProvider = function (int $attemptNumber, ?string $lastEventId = null) use (
            $method,
            $url,
            $curlOptions,
            &$mockedRequests
        ): MockedRequest {
            $modifiedOptions = $curlOptions;
            if ($lastEventId !== null) {
                $headers = $modifiedOptions[CURLOPT_HTTPHEADER] ?? [];
                if (! is_array($headers)) {
                    $headers = [];
                }
                $headers[] = "Last-Event-ID: {$lastEventId}";
                $modifiedOptions[CURLOPT_HTTPHEADER] = $headers;
            }

            $match = $this->requestMatcher->findMatchingMock(
                $mockedRequests,
                $method,
                $url,
                $modifiedOptions
            );

            if ($match === null) {
                throw new MockAssertionException(
                    "No SSE mock found for attempt #{$attemptNumber}: {$method} {$url}"
                );
            }

            $mock = $match['mock'];
            $this->requestRecorder->recordRequest($method, $url, $modifiedOptions);

            if (! $mock->isPersistent()) {
                array_splice($mockedRequests, $match['index'], 1);
            }

            if (! $mock->isSSE()) {
                throw new \RuntimeException(
                    'Mock matched for SSE request but is not configured as SSE. ' .
                        'Use ->respondWithSSE() instead of ->respondWith() or ->respondJson()'
                );
            }

            return $mock;
        };

        $onReconnectCallback = is_callable([$reconnectConfig, 'onReconnect']) ? $reconnectConfig->onReconnect : null;

        return $this->responseFactory->createRetryableMockedSSE(
            $reconnectConfig,
            $mockProvider,
            $onEvent,
            $onError,
            $onReconnectCallback
        );
    }

    /**
     * @param array<int, mixed> $curlOptions
     */
    private function isSSERequest(array $curlOptions): bool
    {
        if (! isset($curlOptions[CURLOPT_HTTPHEADER])) {
            return false;
        }

        $headers = $curlOptions[CURLOPT_HTTPHEADER];
        if (! is_array($headers)) {
            return false;
        }

        foreach ($headers as $header) {
            if (is_string($header) && stripos($header, 'Accept: text/event-stream') !== false) {
                return true;
            }
        }

        return false;
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
        $methodValue = $options['method'] ?? 'GET';
        $method = is_string($methodValue) ? strtoupper($methodValue) : 'GET';

        $curlOptions = $this->normalizeFetchOptions($url, $options);
        /** @var array<int, mixed> $curlOnlyOptions */
        $curlOnlyOptions = array_filter($curlOptions, 'is_int', ARRAY_FILTER_USE_KEY);

        $retryConfig = $this->extractRetryConfig($options);
        $cacheConfig = $this->extractCacheConfig($options);

        if ($this->isSSERequested($options)) {
            $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOnlyOptions);

            if ($match !== null) {
                $mock = $match['mock'];
                if (! $mock->isPersistent()) {
                    array_splice($mockedRequests, $match['index'], 1);
                }

                if ($mock->isSSE()) {
                    $onEvent = $options['on_event'] ?? $options['onEvent'] ?? null;
                    $onError = $options['on_error'] ?? $options['onError'] ?? null;

                    // @phpstan-ignore-next-line - SSEResponse is part of the union type but PHPStan can't verify due to template covariance
                    return $this->responseFactory->createMockedSSE(
                        $mock,
                        is_callable($onEvent) ? $onEvent : null,
                        is_callable($onError) ? $onError : null
                    );
                }
            }
        }

        if ($cacheConfig !== null && $this->tryServeFromCache($url, $method, $cacheConfig)) {
            $cachedResponse = $this->cacheManager->getCachedResponse($url, $cacheConfig);
            if ($cachedResponse === null) {
                throw new \RuntimeException('Cache indicated response available but returned null');
            }

            return Promise::resolved($cachedResponse);
        }

        if ($retryConfig !== null) {
            // @phpstan-ignore-next-line - SSEResponse is part of the union type but PHPStan can't verify due to template covariance
            return $this->executeWithMockRetry($url, $options, $retryConfig, $method, $mockedRequests, $createStream);
        }

        $this->requestRecorder->recordRequest($method, $url, $curlOnlyOptions);

        $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOnlyOptions);

        if ($match !== null) {
            return $this->handleMockedResponse($match, $options, $mockedRequests, $cacheConfig, $url, $method, $createStream);
        }

        if ((bool)($globalSettings['strict_matching'] ?? true)) {
            throw UnexpectedRequestException::noMatchFound($method, $url, $curlOnlyOptions, $mockedRequests);
        }

        if (! (bool)($globalSettings['allow_passthrough'] ?? false)) {
            throw UnexpectedRequestException::noMatchFound($method, $url, $curlOnlyOptions, $mockedRequests);
        }

        if ($parentFetch === null) {
            throw new \RuntimeException('No parent fetch available');
        }

        /** @var PromiseInterface<Response>|CancellablePromiseInterface<StreamingResponse|array<string, mixed>> $result */
        $result = $parentFetch($url, $options);

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function isSSERequested(array $options): bool
    {
        return isset($options['sse']) && $options['sse'] === true;
    }

    private function tryServeFromCache(string $url, string $method, ?CacheConfig $cacheConfig): bool
    {
        if ($cacheConfig === null || $method !== 'GET') {
            return false;
        }

        $cachedResponse = $this->cacheManager->getCachedResponse($url, $cacheConfig);

        if ($cachedResponse !== null) {
            $this->requestRecorder->recordRequest('GET (FROM CACHE)', $url, []);

            return true;
        }

        return false;
    }

    /**
     * @param array{mock: MockedRequest, index: int} $match
     * @param array<string, mixed> $options
     * @param list<MockedRequest> $mockedRequests
     * @return PromiseInterface<Response>|CancellablePromiseInterface<StreamingResponse|array<string, mixed>>
     */
    private function handleMockedResponse(
        array $match,
        array $options,
        array &$mockedRequests,
        ?CacheConfig $cacheConfig,
        string $url,
        string $method,
        ?callable $createStream = null
    ): PromiseInterface|CancellablePromiseInterface {
        $mock = $match['mock'];

        if (! $mock->isPersistent()) {
            array_splice($mockedRequests, $match['index'], 1);
        }

        if (isset($options['download'])) {
            $destination = is_string($options['download']) ? $options['download'] : '';
            if ($destination === '') {
                throw new \InvalidArgumentException('Download destination must be a non-empty string');
            }

            // @phpstan-ignore-next-line - array<string, mixed> is compatible with union return type but PHPStan can't verify due to template covariance
            return $this->responseFactory->createMockedDownload($mock, $destination, $this->fileManager);
        }

        if (isset($options['stream']) && $options['stream'] === true) {
            $onChunkRaw = $options['on_chunk'] ?? $options['onChunk'] ?? null;
            $onChunk = is_callable($onChunkRaw) ? $onChunkRaw : null;

            $createStreamFn = $createStream ?? fn (string $body): StreamInterface => (new HttpHandler())->createStream($body);

            // @phpstan-ignore-next-line - StreamingResponse is part of union return type but PHPStan can't verify due to template covariance
            return $this->responseFactory->createMockedStream($mock, $onChunk, $createStreamFn);
        }

        $responsePromise = $this->responseFactory->createMockedResponse($mock);

        return $responsePromise->then(function (Response $response) use ($cacheConfig, $url) {
            if ($cacheConfig !== null && $response->ok()) {
                $this->cacheManager->cacheResponse($url, $response, $cacheConfig);
            }

            return $response;
        });
    }

    /**
     * @param array<int|string, mixed> $curlOptions
     * @param list<MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
     * @return PromiseInterface<Response>
     */
    private function executeMockedRequest(
        string $url,
        array $curlOptions,
        array &$mockedRequests,
        array $globalSettings,
        ?RetryConfig $retryConfig,
        ?callable $parentSendRequest
    ): PromiseInterface {
        $methodValue = $curlOptions[CURLOPT_CUSTOMREQUEST] ?? 'GET';
        $method = is_string($methodValue) ? $methodValue : 'GET';

        /** @var array<int, mixed> $curlOnlyOptions */
        $curlOnlyOptions = array_filter($curlOptions, 'is_int', ARRAY_FILTER_USE_KEY);

        $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOnlyOptions);

        if ($retryConfig !== null && $match !== null) {
            $retryResult = $this->executeWithMockRetry($url, $curlOptions, $retryConfig, $method, $mockedRequests, null);

            // Since executeWithMockRetry can return Response|StreamingResponse|array, we need to ensure it's Response
            return $retryResult->then(function ($response) {
                if ($response instanceof Response) {
                    return $response;
                }

                throw new \RuntimeException('Expected Response but got different type from retry');
            });
        }

        $this->requestRecorder->recordRequest($method, $url, $curlOnlyOptions);

        if ($match !== null) {
            $mockedResult = $this->handleMockedResponse($match, [], $mockedRequests, null, $url, $method, null);

            // Ensure we return only Response type
            return $mockedResult->then(function ($response) {
                if ($response instanceof Response) {
                    return $response;
                }

                throw new \RuntimeException('Expected Response but got different type from mock');
            });
        }

        if ((bool)($globalSettings['strict_matching'])) {
            throw new MockException("No mock found for: {$method} {$url}");
        }

        if (! (bool)($globalSettings['allow_passthrough'])) {
            throw new MockException("Passthrough disabled and no mock found for: {$method} {$url}");
        }

        if ($parentSendRequest === null) {
            throw new \RuntimeException('No parent send request available');
        }

        /** @var PromiseInterface<Response> $result */
        $result = $parentSendRequest($url, $curlOptions, null, $retryConfig);

        return $result;
    }

    /**
     * @param array<int|string, mixed> $options
     * @param list<MockedRequest> $mockedRequests
     * @return PromiseInterface<Response|StreamingResponse|array<string, mixed>>
     */
    private function executeWithMockRetry(
        string $url,
        array $options,
        RetryConfig $retryConfig,
        string $method,
        array &$mockedRequests,
        ?callable $createStream = null
    ): PromiseInterface {
        /** @var CancellablePromise<Response|StreamingResponse|array<string, mixed>> $finalPromise */
        $finalPromise = new CancellablePromise();
        $curlOptions = $this->normalizeFetchOptions($url, $options);
        /** @var array<int, mixed> $curlOnlyOptions */
        $curlOnlyOptions = array_filter($curlOptions, 'is_int', ARRAY_FILTER_USE_KEY);

        $mockProvider = function (int $attemptNumber) use ($method, $url, $curlOnlyOptions, &$mockedRequests): MockedRequest {
            $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOnlyOptions);

            if ($match === null) {
                throw new MockAssertionException("No mock found for attempt #{$attemptNumber}: {$method} {$url}");
            }

            $mock = $match['mock'];
            $this->requestRecorder->recordRequest($method, $url, $curlOnlyOptions);

            if (! $mock->isPersistent()) {
                array_splice($mockedRequests, $match['index'], 1);
            }

            return $mock;
        };

        $retryPromise = $this->responseFactory->createRetryableMockedResponse($retryConfig, $mockProvider);

        $retryPromise->then(
            function (Response $successfulResponse) use ($options, $finalPromise, $createStream): void {
                if (isset($options['download'])) {
                    $destPath = is_string($options['download']) ? $options['download'] : $this->fileManager->createTempFile();
                    file_put_contents($destPath, $successfulResponse->body());
                    $finalPromise->resolve([
                        'file' => $destPath,
                        'status' => $successfulResponse->status(),
                        'headers' => $successfulResponse->headers(),
                        'size' => strlen($successfulResponse->body()),
                        'protocol_version' => '1.1',
                    ]);
                } elseif (isset($options['stream']) && $options['stream'] === true) {
                    $onChunkRaw = $options['on_chunk'] ?? $options['onChunk'] ?? null;
                    $onChunk = is_callable($onChunkRaw) ? $onChunkRaw : null;
                    $body = $successfulResponse->body();

                    if ($onChunk !== null) {
                        $onChunk($body);
                    }

                    $createStreamFn = $createStream ?? fn (string $b): StreamInterface => (new HttpHandler())->createStream($b);

                    /** @var StreamInterface $stream */
                    $stream = $createStreamFn($body);
                    $finalPromise->resolve(new StreamingResponse($stream, $successfulResponse->status(), $successfulResponse->headers()));
                } else {
                    $finalPromise->resolve($successfulResponse);
                }
            },
            function ($reason) use ($finalPromise): void {
                $finalPromise->reject($reason);
            }
        );

        if ($retryPromise instanceof CancellablePromiseInterface) {
            $finalPromise->setCancelHandler(fn () => $retryPromise->cancel());
        }

        return $finalPromise;
    }
}
