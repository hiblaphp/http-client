<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Utilities\Executors;

use Hibla\HttpClient\Response;
use Hibla\HttpClient\RetryConfig;
use Hibla\HttpClient\Testing\Exceptions\MockAssertionException;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\RequestMatcher;
use Hibla\HttpClient\Testing\Utilities\RequestRecorder;
use Hibla\HttpClient\Testing\Utilities\ResponseFactory;
use Hibla\Promise\Interfaces\PromiseInterface;

class RetryableRequestExecutor
{
    private RequestMatcher $requestMatcher;
    private ResponseFactory $responseFactory;
    private RequestRecorder $requestRecorder;

    public function __construct(
        RequestMatcher $requestMatcher,
        ResponseFactory $responseFactory,
        RequestRecorder $requestRecorder
    ) {
        $this->requestMatcher = $requestMatcher;
        $this->responseFactory = $responseFactory;
        $this->requestRecorder = $requestRecorder;
    }

    /**
     * @param array<int|string, mixed> $curlOptions
     * @param list<MockedRequest> $mockedRequests
     * @return PromiseInterface<Response>
     */
    public function executeWithRetry(
        string $url,
        array $curlOptions,
        RetryConfig $retryConfig,
        string $method,
        array &$mockedRequests
    ): PromiseInterface {
        /** @var array<int, mixed> $curlOnlyOptions */
        $curlOnlyOptions = array_filter($curlOptions, 'is_int', ARRAY_FILTER_USE_KEY);

        $mockProvider = $this->createMockProvider($method, $url, $curlOnlyOptions, $mockedRequests);

        return $this->responseFactory->createRetryableMockedResponse($retryConfig, $mockProvider);
    }

    /**
     * @param array<int, mixed> $curlOnlyOptions
     * @param list<MockedRequest> $mockedRequests
     */
    private function createMockProvider(
        string $method,
        string $url,
        array $curlOnlyOptions,
        array &$mockedRequests
    ): callable {
        return function (int $attemptNumber) use ($method, $url, $curlOnlyOptions, &$mockedRequests): MockedRequest {
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
    }
}