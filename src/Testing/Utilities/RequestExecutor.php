<?php

namespace Hibla\Http\Testing\Utilities;

use Hibla\Http\CacheConfig;
use Hibla\Http\Handlers\HttpHandler;
use Hibla\Http\Response;
use Hibla\Http\RetryConfig;
use Hibla\Http\StreamingResponse;
use Hibla\Http\Testing\Exceptions\MockAssertionException;
use Hibla\Http\Testing\Exceptions\MockException;
use Hibla\Http\Traits\FetchOptionTrait;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

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

    public function executeSendRequest(
        string $url,
        array $curlOptions,
        array &$mockedRequests,
        array $globalSettings,
        ?CacheConfig $cacheConfig = null,
        ?RetryConfig $retryConfig = null,
        ?callable $parentSendRequest = null
    ): PromiseInterface {
        $this->cookieManager->applyCookiesForRequestOptions($curlOptions, $url);

        $method = $curlOptions[CURLOPT_CUSTOMREQUEST] ?? 'GET';

        if ($this->tryServeFromCache($url, $method, $cacheConfig)) {
            return Promise::resolved($this->cacheManager->getCachedResponse($url, $cacheConfig));
        }

        $promise = $this->executeMockedRequest(
            $url,
            $curlOptions,
            $mockedRequests,
            $globalSettings,
            $retryConfig,
            $parentSendRequest
        );

        if (! ($promise instanceof PromiseInterface)) {
            $promise = Promise::resolved($promise);
        }

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

    public function executeFetch(
        string $url,
        array $options,
        array &$mockedRequests,
        array $globalSettings,
        ?callable $parentFetch = null,
        ?callable $createStream = null
    ): PromiseInterface|CancellablePromiseInterface {
        $method = strtoupper($options['method'] ?? 'GET');
        $curlOptions = $this->normalizeFetchOptions($url, $options);
        $retryConfig = $this->extractRetryConfig($options);
        $cacheConfig = $this->extractCacheConfig($options);

        if ($this->tryServeFromCache($url, $method, $cacheConfig)) {
            return Promise::resolved($this->cacheManager->getCachedResponse($url, $cacheConfig));
        }

        if ($retryConfig !== null) {
            return $this->executeWithMockRetry($url, $options, $retryConfig, $method, $mockedRequests, $createStream);
        }

        $this->requestRecorder->recordRequest($method, $url, $curlOptions);

        $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOptions);

        if ($match !== null) {
            return $this->handleMockedResponse($match, $options, $mockedRequests, $cacheConfig, $url, $method, $createStream);
        }

        if ($globalSettings['strict_matching']) {
            throw new MockException("No mock found for: {$method} {$url}");
        }

        if (! $globalSettings['allow_passthrough']) {
            throw new MockException("Passthrough disabled and no mock found for: {$method} {$url}");
        }

        return $parentFetch ? $parentFetch($url, $options) : Promise::rejected(new \RuntimeException('No parent fetch available'));
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
            return $this->responseFactory->createMockedDownload($mock, $options['download'], $this->fileManager);
        }

        if (isset($options['stream']) && $options['stream'] === true) {
            $onChunk = $options['on_chunk'] ?? $options['onChunk'] ?? null;
            $createStream ??= fn ($body) => (new HttpHandler)->createStream($body);

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

    private function executeMockedRequest(
        string $url,
        array $curlOptions,
        array &$mockedRequests,
        array $globalSettings,
        ?RetryConfig $retryConfig,
        ?callable $parentSendRequest
    ): PromiseInterface {
        $method = $curlOptions[CURLOPT_CUSTOMREQUEST] ?? 'GET';
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

        return $parentSendRequest
            ? $parentSendRequest($url, $curlOptions, null, $retryConfig)
            : Promise::rejected(new \RuntimeException('No parent send request available'));
    }

    private function executeWithMockRetry(
        string $url,
        array $options,
        RetryConfig $retryConfig,
        string $method,
        array &$mockedRequests,
        ?callable $createStream = null
    ): PromiseInterface {
        $finalPromise = new CancellablePromise;
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        $retryPromise = $this->responseFactory->createRetryableMockedResponse(
            $retryConfig,
            function (int $attemptNumber) use ($method, $url, $curlOptions, &$mockedRequests) {
                $this->requestRecorder->recordRequest($method, $url, $curlOptions);
                $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOptions);
                if ($match === null) {
                    throw new MockAssertionException("No mock for attempt #{$attemptNumber}: {$method} {$url}");
                }
                if (! $match['mock']->isPersistent()) {
                    array_splice($mockedRequests, $match['index'], 1);
                }

                return $match['mock'];
            }
        );

        $retryPromise->then(
            function ($successfulResponse) use ($options, $finalPromise, $createStream) {
                if (isset($options['download'])) {
                    $destPath = $options['download'] ?? $this->fileManager->createTempFile();
                    file_put_contents($destPath, $successfulResponse->body());
                    $result = [
                        'file' => $destPath,
                        'status' => $successfulResponse->status(),
                        'headers' => $successfulResponse->headers(),
                        'size' => strlen($successfulResponse->body()),
                    ];
                    $finalPromise->resolve($result);
                } elseif (isset($options['stream']) && $options['stream'] === true) {
                    $onChunk = $options['on_chunk'] ?? null;
                    $body = $successfulResponse->body();
                    if ($onChunk) {
                        $onChunk($body);
                    }
                    $createStream ??= fn ($body) => (new HttpHandler)->createStream($body);
                    $finalPromise->resolve(new StreamingResponse(
                        $createStream($body),
                        $successfulResponse->status(),
                        $successfulResponse->headers()
                    ));
                } else {
                    $finalPromise->resolve($successfulResponse);
                }
            },
            fn ($reason) => $finalPromise->reject($reason)
        );

        if ($retryPromise instanceof CancellablePromiseInterface) {
            $finalPromise->setCancelHandler(fn () => $retryPromise->cancel());
        }

        return $finalPromise;
    }
}
