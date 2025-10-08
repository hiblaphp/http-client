<?php

namespace Hibla\HttpClient\Testing\Utilities\Executors;

use Hibla\HttpClient\Testing\Exceptions\MockAssertionException;
use Hibla\HttpClient\Testing\Exceptions\UnexpectedRequestException;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\RequestMatcher;
use Hibla\HttpClient\Testing\Utilities\RequestRecorder;
use Hibla\HttpClient\Testing\Utilities\ResponseFactory;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;

class SSERequestExecutor
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
     * @param array<int, mixed> $curlOptions
     * @param list<MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
     * @param mixed $reconnectConfig
     * @return CancellablePromiseInterface<\Hibla\HttpClient\SSE\SSEResponse>
     */
    public function execute(
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

        if ($this->shouldUseRetry($reconnectConfig)) {
            /** @var \Hibla\HttpClient\SSE\SSEReconnectConfig $reconnectConfig */
            return $this->executeWithRetry(
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

        return $this->executeSimple(
            $url,
            $curlOptions,
            $mockedRequests,
            $globalSettings,
            $method,
            $onEvent,
            $onError,
            $parentSSE,
            $reconnectConfig
        );
    }

    /**
     * @param mixed $reconnectConfig
     */
    private function shouldUseRetry($reconnectConfig): bool
    {
        return $reconnectConfig instanceof \Hibla\HttpClient\SSE\SSEReconnectConfig
            && $reconnectConfig->enabled;
    }

    /**
     * @param array<int, mixed> $curlOptions
     * @param list<MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
     * @param mixed $reconnectConfig
     * @return CancellablePromiseInterface<\Hibla\HttpClient\SSE\SSEResponse>
     */
    private function executeSimple(
        string $url,
        array $curlOptions,
        array &$mockedRequests,
        array $globalSettings,
        string $method,
        ?callable $onEvent,
        ?callable $onError,
        ?callable $parentSSE,
        $reconnectConfig
    ): CancellablePromiseInterface {
        $this->requestRecorder->recordRequest($method, $url, $curlOptions);

        $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOptions);

        if ($match !== null) {
            return $this->handleMatchedSSE($match, $mockedRequests, $onEvent, $onError);
        }

        return $this->handleNoMatch(
            $method,
            $url,
            $curlOptions,
            $mockedRequests,
            $globalSettings,
            $parentSSE,
            $onEvent,
            $onError,
            $reconnectConfig
        );
    }

    /**
     * @param array{mock: MockedRequest, index: int} $match
     * @param list<MockedRequest> $mockedRequests
     * @return CancellablePromiseInterface<\Hibla\HttpClient\SSE\SSEResponse>
     */
    private function handleMatchedSSE(
        array $match,
        array &$mockedRequests,
        ?callable $onEvent,
        ?callable $onError
    ): CancellablePromiseInterface {
        $mock = $match['mock'];

        if (! $mock->isPersistent()) {
            array_splice($mockedRequests, $match['index'], 1);
        }

        if (! $mock->isSSE()) {
            throw new \RuntimeException(
                'Mock matched for SSE request but is not configured as SSE. ' .
                    'Use ->respondWithSSE() or ->sseInfiniteStream() or other SSE methods'
            );
        }

        return $this->responseFactory->createMockedSSE($mock, $onEvent, $onError);
    }

    /**
     * @param array<int, mixed> $curlOptions
     * @param list<MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
     * @param mixed $reconnectConfig
     * @return CancellablePromiseInterface<\Hibla\HttpClient\SSE\SSEResponse>
     */
    private function handleNoMatch(
        string $method,
        string $url,
        array $curlOptions,
        array $mockedRequests,
        array $globalSettings,
        ?callable $parentSSE,
        ?callable $onEvent,
        ?callable $onError,
        $reconnectConfig
    ): CancellablePromiseInterface {
        if ((bool)($globalSettings['strict_matching'] ?? true)) {
            throw UnexpectedRequestException::noMatchFound($method, $url, $curlOptions, $mockedRequests);
        }

        if (! (bool)($globalSettings['allow_passthrough'] ?? false)) {
            throw UnexpectedRequestException::noMatchFound($method, $url, $curlOptions, $mockedRequests);
        }

        if ($parentSSE === null) {
            throw new \RuntimeException('No parent SSE handler available');
        }

        /** @var CancellablePromiseInterface<\Hibla\HttpClient\SSE\SSEResponse> $result */
        $result = $parentSSE($url, [], $onEvent, $onError, $reconnectConfig);

        return $result;
    }

    /**
     * @param array<int, mixed> $curlOptions
     * @param list<MockedRequest> $mockedRequests
     * @param array<string, mixed> $globalSettings
     * @return CancellablePromiseInterface<\Hibla\HttpClient\SSE\SSEResponse>
     */
    private function executeWithRetry(
        string $url,
        array $curlOptions,
        array &$mockedRequests,
        array $globalSettings,
        ?callable $onEvent,
        ?callable $onError,
        \Hibla\HttpClient\SSE\SSEReconnectConfig $reconnectConfig,
        ?callable $parentSSE
    ): CancellablePromiseInterface {
        $method = 'GET';

        $mockProvider = $this->createMockProvider($method, $url, $curlOptions, $mockedRequests);
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
     * @param list<MockedRequest> $mockedRequests
     */
    private function createMockProvider(
        string $method,
        string $url,
        array $curlOptions,
        array &$mockedRequests
    ): callable {
        return function (int $attemptNumber, ?string $lastEventId = null) use (
            $method,
            $url,
            $curlOptions,
            &$mockedRequests
        ): MockedRequest {
            $modifiedOptions = $this->addLastEventId($curlOptions, $lastEventId);

            $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $modifiedOptions);

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
    }

    /**
     * @param array<int, mixed> $curlOptions
     * @return array<int, mixed>
     */
    private function addLastEventId(array $curlOptions, ?string $lastEventId): array
    {
        if ($lastEventId === null) {
            return $curlOptions;
        }

        $headers = $curlOptions[CURLOPT_HTTPHEADER] ?? [];
        if (! is_array($headers)) {
            $headers = [];
        }
        $headers[] = "Last-Event-ID: {$lastEventId}";
        $curlOptions[CURLOPT_HTTPHEADER] = $headers;

        return $curlOptions;
    }
}
