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
        if ($this->isSSERequest($curlOptions)) {
            throw new \InvalidArgumentException(
                'SSE requests should use $http->request()->sse() or $http->sse() directly, not send() or get()/post() methods'
            );
        }

        $this->cookieManager->applyCookiesForRequestOptions($curlOptions, $url);

        $method = $curlOptions[CURLOPT_CUSTOMREQUEST] ?? 'GET';

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
                return $parentSendRequest($url, $curlOptions, $cacheConfig, $retryConfig);
            }

            throw UnexpectedRequestException::noMatchFound(
                $method,
                $url,
                $curlOptions,
                $mockedRequests
            );
        }

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

        $this->requestRecorder->recordRequest($method, $url, $curlOptions);

        $match = $this->requestMatcher->findMatchingMock(
            $mockedRequests,
            $method,
            $url,
            $curlOptions
        );

        if ($match !== null) {
            $mock = $match['mock'];

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

        return $parentSSE
            ? $parentSSE($url, [], $onEvent, $onError, $reconnectConfig)
            : throw new \RuntimeException('No parent SSE handler available');
    }

    /**
     * Check if the request is configured for SSE.
     */
    private function isSSERequest(array $curlOptions): bool
    {
        if (isset($curlOptions[CURLOPT_HTTPHEADER])) {
            foreach ($curlOptions[CURLOPT_HTTPHEADER] as $header) {
                if (stripos($header, 'Accept: text/event-stream') !== false) {
                    return true;
                }
            }
        }

        return false;
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

        if ($this->isSSERequested($options)) {
            $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOptions);

            if ($match !== null) {
                $mock = $match['mock'];

                if (!$mock->isPersistent()) {
                    array_splice($mockedRequests, $match['index'], 1);
                }

                if ($mock->isSSE()) {
                    $onEvent = $options['on_event'] ?? $options['onEvent'] ?? null;
                    $onError = $options['on_error'] ?? $options['onError'] ?? null;

                    return $this->responseFactory->createMockedSSE($mock, $onEvent, $onError);
                }
            }
        }


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

        return $parentFetch ? $parentFetch($url, $options) : Promise::rejected(new \RuntimeException('No parent fetch available'));
    }

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
            $createStream ??= fn($body) => (new HttpHandler)->createStream($body);

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
        echo "\n=== Execute With Mock Retry ===\n";
        echo "URL: {$url}\n";
        echo "Method: {$method}\n";
        echo "MaxRetries: {$retryConfig->maxRetries}\n";

        $finalPromise = new CancellablePromise;
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        // Collect all mocks upfront for the retry sequence
        $collectedMocks = [];
        $maxAttempts = $retryConfig->maxRetries + 1; // +1 for initial attempt

        echo "Collecting mocks - need up to {$maxAttempts} mocks\n";

        for ($i = 0; $i < $maxAttempts; $i++) {
            $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOptions);

            if ($match === null) {
                echo "No more mocks found after collecting " . count($collectedMocks) . " mocks\n";
                break; // No more mocks available
            }

            echo "Collected mock #" . ($i + 1) . "\n";
            echo "  - Should fail: " . ($match['mock']->shouldFail() ? 'YES' : 'NO') . "\n";
            echo "  - Is persistent: " . ($match['mock']->isPersistent() ? 'YES' : 'NO') . "\n";

            $collectedMocks[] = $match['mock'];

            // Remove the mock if it's not persistent
            if (!$match['mock']->isPersistent()) {
                array_splice($mockedRequests, $match['index'], 1);
                echo "  - Removed from queue (non-persistent)\n";
            } else {
                // For persistent mocks, we can reuse the same one
                // So we don't need to collect more
                echo "  - Keeping in queue (persistent) - stopping collection\n";
                break;
            }
        }

        if (empty($collectedMocks)) {
            echo "ERROR: No mocks collected!\n";
            throw new MockAssertionException("No mock found for: {$method} {$url}");
        }

        echo "Total mocks collected: " . count($collectedMocks) . "\n";

        $retryPromise = $this->responseFactory->createRetryableMockedResponse(
            $retryConfig,
            function (int $attemptNumber) use ($method, $url, $curlOptions, $collectedMocks) {
                echo "\nMock provider called for attempt #{$attemptNumber}\n";

                $this->requestRecorder->recordRequest($method, $url, $curlOptions);

                // Use the collected mocks by index
                // If we've exhausted the collected mocks, reuse the last one (for persistent mocks)
                $mockIndex = min($attemptNumber - 1, count($collectedMocks) - 1);

                echo "Using mock at index {$mockIndex} (0-based)\n";

                $mock = $collectedMocks[$mockIndex];

                if ($mock === null) {
                    echo "ERROR: Mock at index {$mockIndex} is null!\n";
                    throw new MockAssertionException("No mock for attempt #{$attemptNumber}: {$method} {$url}");
                }

                return $mock;
            }
        );

        $retryPromise->then(
            function ($successfulResponse) use ($options, $finalPromise, $createStream) {
                echo "\n=== Retry Promise Resolved Successfully ===\n";

                if (isset($options['download'])) {
                    $destPath = $options['download'];
                    if (!is_string($destPath)) {
                        $destPath = $this->fileManager->createTempFile();
                    }
                    file_put_contents($destPath, $successfulResponse->body());
                    $result = [
                        'file' => $destPath,
                        'status' => $successfulResponse->status(),
                        'headers' => $successfulResponse->headers(),
                        'size' => strlen($successfulResponse->body()),
                    ];
                    $finalPromise->resolve($result);
                } elseif (isset($options['stream']) && $options['stream'] === true) {
                    $onChunk = $options['on_chunk'] ?? $options['onChunk'] ?? null;
                    $body = $successfulResponse->body();
                    if ($onChunk) {
                        $onChunk($body);
                    }
                    $createStream ??= fn($body) => (new HttpHandler)->createStream($body);
                    $finalPromise->resolve(new StreamingResponse(
                        $createStream($body),
                        $successfulResponse->status(),
                        $successfulResponse->headers()
                    ));
                } else {
                    echo "Resolving final promise with response\n";
                    $finalPromise->resolve($successfulResponse);
                }
            },
            function ($reason) use ($finalPromise) {
                echo "\n=== Retry Promise REJECTED ===\n";
                echo "Reason: " . $reason->getMessage() . "\n";
                $finalPromise->reject($reason);
            }
        );

        if ($retryPromise instanceof CancellablePromiseInterface) {
            $finalPromise->setCancelHandler(fn() => $retryPromise->cancel());
        }

        return $finalPromise;
    }
}
