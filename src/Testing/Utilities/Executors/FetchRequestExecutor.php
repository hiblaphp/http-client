<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Utilities\Executors;

use Hibla\HttpClient\Testing\Exceptions\UnexpectedRequestException;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\FileManager;
use Hibla\HttpClient\Testing\Utilities\Handlers\ResponseTypeHandler;
use Hibla\HttpClient\Testing\Utilities\RequestMatcher;
use Hibla\HttpClient\Testing\Utilities\RequestRecorder;
use Hibla\HttpClient\Testing\Utilities\ResponseFactory;
use Hibla\HttpClient\Testing\Utilities\Validators\RequestValidator;
use Hibla\HttpClient\Traits\FetchOptionTrait;
use Hibla\Promise\Interfaces\PromiseInterface;

class FetchRequestExecutor
{
    use FetchOptionTrait;

    private RequestMatcher $requestMatcher;
    private ResponseFactory $responseFactory;
    private FileManager $fileManager;
    private RequestRecorder $requestRecorder;
    private RequestValidator $validator;
    private ResponseTypeHandler $responseTypeHandler;
    private RetryableRequestExecutor $retryExecutor;

    public function __construct(
        RequestMatcher $requestMatcher,
        ResponseFactory $responseFactory,
        FileManager $fileManager,
        RequestRecorder $requestRecorder,
        RequestValidator $validator
    ) {
        $this->requestMatcher = $requestMatcher;
        $this->responseFactory = $responseFactory;
        $this->fileManager = $fileManager;
        $this->requestRecorder = $requestRecorder;
        $this->validator = $validator;

        $this->responseTypeHandler = new ResponseTypeHandler(
            $responseFactory,
            $fileManager,
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
     * @return PromiseInterface<mixed>
     */
    public function execute(
        string $url,
        array $options,
        array &$mockedRequests,
        array $globalSettings,
        ?callable $parentFetch = null,
        ?callable $createStream = null
    ): PromiseInterface {
        $method = $this->extractMethod($options);
        $curlOptions = $this->normalizeFetchOptions($url, $options);
        /** @var array<int, mixed> $curlOnlyOptions */
        $curlOnlyOptions = array_filter($curlOptions, 'is_int', ARRAY_FILTER_USE_KEY);

        $retryConfig = $this->extractRetryConfig($options);

        if ($this->validator->isSSERequested($options)) {
            /** @var PromiseInterface<mixed> */
            return $this->handleSSERequest($url, $options, $method, $curlOnlyOptions, $mockedRequests);
        }

        if ($retryConfig !== null) {
            /** @var PromiseInterface<mixed> */
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

        return $this->executeStandard(
            $url,
            $options,
            $method,
            $curlOnlyOptions,
            $mockedRequests,
            $globalSettings,
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
     * @return PromiseInterface<mixed>
     */
    private function handleSSERequest(
        string $url,
        array $options,
        string $method,
        array $curlOnlyOptions,
        array &$mockedRequests
    ): PromiseInterface {
        $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOnlyOptions);

        if ($match !== null) {
            $mock = $match['mock'];
            if (! $mock->isPersistent()) {
                array_splice($mockedRequests, $match['index'], 1);
            }

            if ($mock->isSSE()) {
                $onEvent = $options['on_event'] ?? $options['onEvent'] ?? null;
                $onError = $options['on_error'] ?? $options['onError'] ?? null;

                /** @var PromiseInterface<mixed> */
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
     * @return PromiseInterface<mixed>
     */
    private function executeStandard(
        string $url,
        array $options,
        string $method,
        array $curlOnlyOptions,
        array &$mockedRequests,
        array $globalSettings,
        ?callable $parentFetch,
        ?callable $createStream
    ): PromiseInterface {
        $this->requestRecorder->recordRequest($method, $url, array_merge($options, $curlOnlyOptions));

        $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOnlyOptions);

        if ($match !== null) {
            /** @var PromiseInterface<mixed> */
            return $this->responseTypeHandler->handleMockedResponse(
                $match,
                $options,
                $mockedRequests,
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
     * @return PromiseInterface<mixed>
     */
    private function handleNoMatch(
        string $method,
        string $url,
        array $curlOnlyOptions,
        array $mockedRequests,
        array $globalSettings,
        ?callable $parentFetch,
        array $options
    ): PromiseInterface {
        if ((bool)($globalSettings['strict_matching'] ?? true)) {
            throw UnexpectedRequestException::noMatchFound($method, $url, $curlOnlyOptions, $mockedRequests);
        }

        if (! (bool)($globalSettings['allow_passthrough'] ?? false)) {
            throw UnexpectedRequestException::noMatchFound($method, $url, $curlOnlyOptions, $mockedRequests);
        }

        if ($parentFetch === null) {
            throw new \RuntimeException('No parent fetch available');
        }

        /** @var PromiseInterface<mixed> $result */
        $result = $parentFetch($url, $options);

        return $result;
    }
}
