<?php

namespace Hibla\HttpClient\Testing\Utilities\Executors;

use Hibla\HttpClient\CacheConfig;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\RetryConfig;
use Hibla\HttpClient\Testing\Exceptions\UnexpectedRequestException;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\CookieManager;
use Hibla\HttpClient\Testing\Utilities\Handlers\CacheHandler;
use Hibla\HttpClient\Testing\Utilities\RequestMatcher;
use Hibla\HttpClient\Testing\Utilities\RequestRecorder;
use Hibla\HttpClient\Testing\Utilities\ResponseFactory;
use Hibla\HttpClient\Testing\Utilities\Validators\RequestValidator;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

class StandardRequestExecutor
{
    private RequestMatcher $requestMatcher;
    private ResponseFactory $responseFactory;
    private CookieManager $cookieManager;
    private RequestRecorder $requestRecorder;
    private CacheHandler $cacheHandler;
    private RequestValidator $validator;
    private RetryableRequestExecutor $retryExecutor;

    public function __construct(
        RequestMatcher $requestMatcher,
        ResponseFactory $responseFactory,
        CookieManager $cookieManager,
        RequestRecorder $requestRecorder,
        CacheHandler $cacheHandler,
        RequestValidator $validator
    ) {
        $this->requestMatcher = $requestMatcher;
        $this->responseFactory = $responseFactory;
        $this->cookieManager = $cookieManager;
        $this->requestRecorder = $requestRecorder;
        $this->cacheHandler = $cacheHandler;
        $this->validator = $validator;

        $this->retryExecutor = new RetryableRequestExecutor(
            $requestMatcher,
            $responseFactory,
            $requestRecorder
        );
    }

    /**
     * @param array<int|string, mixed> $curlOptions
     * @param list<MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
     * @return PromiseInterface<Response>
     */
    public function execute(
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

        $this->validator->validateNotSSERequest($curlOnlyOptions);
        $this->cookieManager->applyCookiesForRequestOptions($curlOptions, $url);

        $method = $this->extractMethod($curlOptions);

        if ($this->cacheHandler->tryServeFromCache($url, $method, $cacheConfig)) {
            /** @var Response $cachedResponse */
            $cachedResponse = $this->cacheHandler->getCachedResponse($url, $cacheConfig);

            return Promise::resolved($cachedResponse);
        }

        $matchedMock = $this->requestMatcher->findMatchingMock(
            $mockedRequests,
            $method,
            $url,
            $curlOnlyOptions
        );

        if ($matchedMock === null) {
            return $this->handleNoMatch(
                $url,
                $curlOptions,
                $method,
                $curlOnlyOptions,
                $mockedRequests,
                $globalSettings,
                $cacheConfig,
                $retryConfig,
                $parentSendRequest
            );
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

        return $this->applyPostProcessing($promise, $curlOptions, $url, $cacheConfig, $method);
    }

    /**
     * @param array<int|string, mixed> $curlOptions
     */
    private function extractMethod(array $curlOptions): string
    {
        return is_string($curlOptions[CURLOPT_CUSTOMREQUEST] ?? null)
            ? $curlOptions[CURLOPT_CUSTOMREQUEST]
            : 'GET';
    }

    /**
     * @param array<int|string, mixed> $curlOptions
     * @param array<int, mixed> $curlOnlyOptions
     * @param list<MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
     * @return PromiseInterface<Response>
     */
    private function handleNoMatch(
        string $url,
        array $curlOptions,
        string $method,
        array $curlOnlyOptions,
        array $mockedRequests,
        array $globalSettings,
        ?CacheConfig $cacheConfig,
        ?RetryConfig $retryConfig,
        ?callable $parentSendRequest
    ): PromiseInterface {
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

    /**
     * @param PromiseInterface<Response> $promise
     * @param array<int|string, mixed> $curlOptions
     * @return PromiseInterface<Response>
     */
    private function applyPostProcessing(
        PromiseInterface $promise,
        array $curlOptions,
        string $url,
        ?CacheConfig $cacheConfig,
        string $method
    ): PromiseInterface {
        return $promise->then(function (Response $response) use ($curlOptions, $url, $cacheConfig, $method) {
            $this->processCookies($response, $curlOptions, $url);
            $this->cacheHandler->cacheIfNeeded($url, $response, $cacheConfig, $method);

            return $response;
        });
    }

    /**
     * @param array<int|string, mixed> $curlOptions
     */
    private function processCookies(Response $response, array $curlOptions, string $url): void
    {
        $rawHeaders = $response->getHeaders();
        $transformedHeaders = [];

        foreach ($rawHeaders as $key => $value) {
            if (is_string($key)) {
                $transformedHeaders[$key] = is_array($value) ? $value : [$value];
            }
        }

        $this->cookieManager->processResponseCookiesForOptions($transformedHeaders, $curlOptions, $url);
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
        $method = $this->extractMethod($curlOptions);
        /** @var array<int, mixed> $curlOnlyOptions */
        $curlOnlyOptions = array_filter($curlOptions, 'is_int', ARRAY_FILTER_USE_KEY);

        if ($retryConfig !== null) {
            return $this->retryExecutor->executeWithRetry(
                $url,
                $curlOptions,
                $retryConfig,
                $method,
                $mockedRequests
            );
        }

        $this->requestRecorder->recordRequest($method, $url, $curlOnlyOptions);

        $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOnlyOptions);

        if ($match !== null) {
            $mock = $match['mock'];
            if (! $mock->isPersistent()) {
                array_splice($mockedRequests, $match['index'], 1);
            }

            return $this->responseFactory->createMockedResponse($mock);
        }

        return $this->handleNoMockFound($method, $url, $globalSettings, $parentSendRequest, $curlOptions, $retryConfig);
    }

    /**
     * @param array<string, mixed> $globalSettings
     * @param array<int|string, mixed> $curlOptions
     * @return PromiseInterface<Response>
     */
    private function handleNoMockFound(
        string $method,
        string $url,
        array $globalSettings,
        ?callable $parentSendRequest,
        array $curlOptions,
        ?RetryConfig $retryConfig
    ): PromiseInterface {
        if ((bool)($globalSettings['allow_passthrough'] ?? false)) {
            if ($parentSendRequest === null) {
                throw new \RuntimeException('No parent send request available');
            }
            /** @var PromiseInterface<Response> $result */
            $result = $parentSendRequest($url, $curlOptions, null, $retryConfig);

            return $result;
        }

        throw new \RuntimeException("No mock found for: {$method} {$url}");
    }
}
