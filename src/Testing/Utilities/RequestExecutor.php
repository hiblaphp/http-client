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
     * @param array<int, mixed> $curlOptions
     * @param array<int, MockedRequest> $mockedRequests
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
        if ($this->isSSERequest($curlOptions)) {
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
            $curlOptions
        );

        if ($matchedMock === null) {
            if ($globalSettings['throw_on_unexpected'] ?? true) {
                throw UnexpectedRequestException::noMatchFound(
                    $method,
                    $url,
                    $curlOptions,
                    $mockedRequests
                );
            }

            if ($globalSettings['allow_passthrough'] ?? false) {
                if ($parentSendRequest === null) {
                    throw new \RuntimeException('No parent send request handler available');
                }
                $result = $parentSendRequest($url, $curlOptions, $cacheConfig, $retryConfig);
                if (!$result instanceof PromiseInterface) {
                    throw new \RuntimeException('Parent send request must return PromiseInterface');
                }
                return $result;
            }

            throw UnexpectedRequestException::noMatchFound(
                $method,
                $url,
                $curlOptions,
                $mockedRequests
            );
        }

        if ($this->tryServeFromCache($url, $method, $cacheConfig)) {
            $cachedResponse = $this->cacheManager->getCachedResponse($url, $cacheConfig);
            if ($cachedResponse === null) {
                throw new \RuntimeException('Cache indicated response available but returned null');
            }
            return Promise::resolved($cachedResponse);
        }

        $promise = $this->executeMockedRequest(
            $url,
            $curlOptions,
            $mockedRequests,
            $globalSettings,
            $retryConfig,
            $parentSendRequest
        );

        $promise = $promise->then(function ($response) use ($curlOptions, $url, $cacheConfig, $method) {
            if ($response instanceof Response) {
                $this->cookieManager->processResponseCookiesForOptions($response->getHeaders(), $curlOptions, $url);

                if ($cacheConfig !== null && $method === 'GET' && $response->ok()) {
                    $this->cacheManager->cacheResponse($url, $response, $cacheConfig);
                }
            }

            return $response;
        });

        return $promise;
    }

    /**
     * @param array<int, mixed> $curlOptions
     * @param array<int, MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
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
        mixed $reconnectConfig = null
    ): CancellablePromiseInterface {
        $method = 'GET';

        if ($reconnectConfig !== null && is_object($reconnectConfig) && property_exists($reconnectConfig, 'enabled') && $reconnectConfig->enabled) {
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

            if (!$mock instanceof MockedRequest) {
                throw new \RuntimeException('Mock must be an instance of MockedRequest');
            }

            if (!$mock->isPersistent()) {
                array_splice($mockedRequests, $match['index'], 1);
            }

            if ($mock->isSSE()) {
                return $this->responseFactory->createMockedSSE($mock, $onEvent, $onError);
            }

            throw new \RuntimeException(
                "Mock matched for SSE request but is not configured as SSE. " .
                    "Use ->respondWithSSE() instead of ->respondWith() or ->respondJson()"
            );
        }

        if ($globalSettings['strict_matching'] ?? true) {
            throw UnexpectedRequestException::noMatchFound(
                $method,
                $url,
                $curlOptions,
                $mockedRequests
            );
        }

        if (!($globalSettings['allow_passthrough'] ?? false)) {
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

        $result = $parentSSE($url, [], $onEvent, $onError, $reconnectConfig);
        if (!$result instanceof CancellablePromiseInterface) {
            throw new \RuntimeException('Parent SSE handler must return CancellablePromiseInterface');
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $curlOptions
     * @param array<int, MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
     * @return CancellablePromiseInterface<\Hibla\Http\SSE\SSEResponse>
     */
    private function executeSSEWithRetry(
        string $url,
        array $curlOptions,
        array &$mockedRequests,
        array $globalSettings,
        ?callable $onEvent,
        ?callable $onError,
        mixed $reconnectConfig,
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
                if (!is_array($headers)) {
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

            if (!$mock instanceof MockedRequest) {
                throw new \RuntimeException('Mock must be an instance of MockedRequest');
            }

            $this->requestRecorder->recordRequest($method, $url, $modifiedOptions);

            if (!$mock->isPersistent()) {
                array_splice($mockedRequests, $match['index'], 1);
            }

            if (!$mock->isSSE()) {
                throw new \RuntimeException(
                    "Mock matched for SSE request but is not configured as SSE. " .
                        "Use ->respondWithSSE() instead of ->respondWith() or ->respondJson()"
                );
            }

            return $mock;
        };

        if (!$reconnectConfig instanceof \Hibla\Http\SSE\SSEReconnectConfig) {
            throw new \RuntimeException('Reconnect config must be an instance of SSEReconnectConfig');
        }

        $onReconnectCallback = is_object($reconnectConfig) && property_exists($reconnectConfig, 'onReconnect')
            ? $reconnectConfig->onReconnect
            : null;

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
        if (!isset($curlOptions[CURLOPT_HTTPHEADER])) {
            return false;
        }

        $headers = $curlOptions[CURLOPT_HTTPHEADER];
        if (!is_array($headers)) {
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
     * @param array<int, MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
     * @return PromiseInterface<Response>|CancellablePromiseInterface<StreamingResponse|array<string, mixed>|\Hibla\Http\SSE\SSEResponse>
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
        $retryConfig = $this->extractRetryConfig($options);
        $cacheConfig = $this->extractCacheConfig($options);

        if ($this->isSSERequested($options)) {
            $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOptions);

            if ($match !== null) {
                $mock = $match['mock'];

                if (!$mock instanceof MockedRequest) {
                    throw new \RuntimeException('Mock must be an instance of MockedRequest');
                }

                if (!$mock->isPersistent()) {
                    array_splice($mockedRequests, $match['index'], 1);
                }

                if ($mock->isSSE()) {
                    $onEvent = $options['on_event'] ?? $options['onEvent'] ?? null;
                    $onError = $options['on_error'] ?? $options['onError'] ?? null;

                    if ($onEvent !== null && !is_callable($onEvent)) {
                        $onEvent = null;
                    }
                    if ($onError !== null && !is_callable($onError)) {
                        $onError = null;
                    }

                    return $this->responseFactory->createMockedSSE($mock, $onEvent, $onError);
                }
            }
        }

        if ($this->tryServeFromCache($url, $method, $cacheConfig)) {
            $cachedResponse = $this->cacheManager->getCachedResponse($url, $cacheConfig);
            if ($cachedResponse === null) {
                throw new \RuntimeException('Cache indicated response available but returned null');
            }
            return Promise::resolved($cachedResponse);
        }

        if ($retryConfig !== null) {
            return $this->executeWithMockRetry($url, $options, $retryConfig, $method, $mockedRequests, $createStream);
        }

        $this->requestRecorder->recordRequest($method, $url, $curlOptions);

        $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOptions);

        if ($match !== null) {
            return $this->handleMockedResponse($match, $options, $mockedRequests, $cacheConfig, $url, $method, $createStream);
        }

        if ($globalSettings['strict_matching'] ?? true) {
            throw UnexpectedRequestException::noMatchFound(
                $method,
                $url,
                $curlOptions,
                $mockedRequests
            );
        }

        if (! ($globalSettings['allow_passthrough'] ?? false)) {
            throw UnexpectedRequestException::noMatchFound(
                $method,
                $url,
                $curlOptions,
                $mockedRequests
            );
        }

        if ($parentFetch === null) {
            throw new \RuntimeException('No parent fetch available');
        }

        $result = $parentFetch($url, $options);
        if (!$result instanceof PromiseInterface) {
            throw new \RuntimeException('Parent fetch must return PromiseInterface');
        }

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
     * @param array{mock: mixed, index: int} $match
     * @param array<string, mixed> $options
     * @param array<int, MockedRequest> $mockedRequests
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

        if (!$mock instanceof MockedRequest) {
            throw new \RuntimeException('Mock must be an instance of MockedRequest');
        }

        if (! $mock->isPersistent()) {
            array_splice($mockedRequests, $match['index'], 1);
        }

        if (isset($options['download'])) {
            $destination = is_string($options['download']) ? $options['download'] : '';
            if ($destination === '') {
                throw new \InvalidArgumentException('Download destination must be a non-empty string');
            }
            return $this->responseFactory->createMockedDownload($mock, $destination, $this->fileManager);
        }

        if (isset($options['stream']) && $options['stream'] === true) {
            $onChunk = $options['on_chunk'] ?? $options['onChunk'] ?? null;
            if ($onChunk !== null && !is_callable($onChunk)) {
                $onChunk = null;
            }

            $createStream ??= fn(string $body): StreamInterface => (new HttpHandler)->createStream($body);

            return $this->responseFactory->createMockedStream($mock, $onChunk, $createStream);
        }

        $responsePromise = $this->responseFactory->createMockedResponse($mock);

        if ($cacheConfig !== null && $method === 'GET') {
            return $responsePromise->then(function ($response) use ($cacheConfig, $url) {
                if ($response instanceof Response && $response->ok()) {
                    $this->cacheManager->cacheResponse($url, $response, $cacheConfig);
                }

                return $response;
            });
        }

        return $responsePromise;
    }

    /**
     * @param array<int, mixed> $curlOptions
     * @param array<int, MockedRequest> $mockedRequests
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

        $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOptions);

        if ($retryConfig !== null && $match !== null) {
            return $this->executeWithMockRetry($url, $curlOptions, $retryConfig, $method, $mockedRequests, null);
        }

        $this->requestRecorder->recordRequest($method, $url, $curlOptions);

        if ($match !== null) {
            return $this->handleMockedResponse(
                $match,
                [],
                $mockedRequests,
                null,
                $url,
                $method,
                null
            );
        }

        if ($globalSettings['strict_matching']) {
            throw new MockException("No mock found for: {$method} {$url}");
        }

        if (! $globalSettings['allow_passthrough']) {
            throw new MockException("Passthrough disabled and no mock found for: {$method} {$url}");
        }

        if ($parentSendRequest === null) {
            throw new \RuntimeException('No parent send request available');
        }

        $result = $parentSendRequest($url, $curlOptions, null, $retryConfig);
        if (!$result instanceof PromiseInterface) {
            throw new \RuntimeException('Parent send request must return PromiseInterface');
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<int, MockedRequest> $mockedRequests
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
        $finalPromise = new CancellablePromise;
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        $mockProvider = function (int $attemptNumber) use ($method, $url, $curlOptions, &$mockedRequests): MockedRequest {
            $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOptions);

            if ($match === null) {
                throw new MockAssertionException("No mock found for attempt #{$attemptNumber}: {$method} {$url}");
            }

            $mock = $match['mock'];

            if (!$mock instanceof MockedRequest) {
                throw new \RuntimeException('Mock must be an instance of MockedRequest');
            }

            $this->requestRecorder->recordRequest($method, $url, $curlOptions);

            if (!$mock->isPersistent()) {
                array_splice($mockedRequests, $match['index'], 1);
            }

            return $mock;
        };

        $retryPromise = $this->responseFactory->createRetryableMockedResponse(
            $retryConfig,
            $mockProvider
        );

        $retryPromise->then(
            function ($successfulResponse) use ($options, $finalPromise, $createStream) {
                if (isset($options['download'])) {
                    $destPath = is_string($options['download']) ? $options['download'] : $this->fileManager->createTempFile();
                    $body = $successfulResponse instanceof Response ? $successfulResponse->body() : '';
                    file_put_contents($destPath, $body);
                    $status = $successfulResponse instanceof Response ? $successfulResponse->status() : 200;
                    $headers = $successfulResponse instanceof Response ? $successfulResponse->headers() : [];

                    $finalPromise->resolve([
                        'file' => $destPath,
                        'status' => $status,
                        'headers' => $headers,
                        'size' => strlen($body),
                    ]);
                } elseif (isset($options['stream']) && $options['stream'] === true) {
                    $onChunk = $options['on_chunk'] ?? $options['onChunk'] ?? null;
                    $body = $successfulResponse instanceof Response ? $successfulResponse->body() : '';

                    if ($onChunk !== null && is_callable($onChunk)) {
                        $onChunk($body);
                    }

                    if ($createStream === null) {
                        $createStream = fn(string $b): StreamInterface => (new HttpHandler)->createStream($b);
                    }

                    $stream = $createStream($body);
                    if (!$stream instanceof StreamInterface) {
                        throw new \RuntimeException('Stream creator must return StreamInterface');
                    }

                    $status = $successfulResponse instanceof Response ? $successfulResponse->status() : 200;
                    $headers = $successfulResponse instanceof Response ? $successfulResponse->headers() : [];

                    $finalPromise->resolve(new StreamingResponse($stream, $status, $headers));
                } else {
                    $finalPromise->resolve($successfulResponse);
                }
            },
            function ($reason) use ($finalPromise) {
                $finalPromise->reject($reason);
            }
        );

        if ($retryPromise instanceof CancellablePromiseInterface) {
            $finalPromise->setCancelHandler(fn() => $retryPromise->cancel());
        }

        return $finalPromise;
    }
}
