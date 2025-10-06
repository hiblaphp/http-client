<?php

namespace Hibla\Http\Testing\Utilities\Executors;

use Hibla\Http\Handlers\HttpHandler;
use Hibla\Http\Response;
use Hibla\Http\RetryConfig;
use Hibla\Http\StreamingResponse;
use Hibla\Http\Testing\Exceptions\MockAssertionException;
use Hibla\Http\Testing\MockedRequest;
use Hibla\Http\Testing\Utilities\FileManager;
use Hibla\Http\Testing\Utilities\RequestMatcher;
use Hibla\Http\Testing\Utilities\RequestRecorder;
use Hibla\Http\Testing\Utilities\ResponseFactory;
use Hibla\Http\Traits\FetchOptionTrait;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Psr\Http\Message\StreamInterface;

class RetryableRequestExecutor
{
    use FetchOptionTrait;

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
        $retryPromise = $this->responseFactory->createRetryableMockedResponse($retryConfig, $mockProvider);

        return $retryPromise->then(function ($response) {
            if ($response instanceof Response) {
                return $response;
            }
            throw new \RuntimeException('Expected Response but got different type from retry');
        });
    }

    /**
     * @param array<int|string, mixed> $options
     * @param list<MockedRequest> $mockedRequests
     * @return PromiseInterface<Response|StreamingResponse|array<string, mixed>>
     */
    public function executeWithMockRetry(
        string $url,
        array $options,
        RetryConfig $retryConfig,
        string $method,
        array &$mockedRequests,
        ?callable $createStream = null,
        ?FileManager $fileManager = null
    ): PromiseInterface {
        /** @var CancellablePromise<Response|StreamingResponse|array<string, mixed>> $finalPromise */
        $finalPromise = new CancellablePromise();
        
        $curlOptions = $this->normalizeFetchOptions($url, $options);
        /** @var array<int, mixed> $curlOnlyOptions */
        $curlOnlyOptions = array_filter($curlOptions, 'is_int', ARRAY_FILTER_USE_KEY);

        $mockProvider = $this->createMockProvider($method, $url, $curlOnlyOptions, $mockedRequests);
        $retryPromise = $this->responseFactory->createRetryableMockedResponse($retryConfig, $mockProvider);

        $retryPromise->then(
            function (Response $successfulResponse) use ($options, $finalPromise, $createStream, $fileManager): void {
                $this->resolveRetryResponse($successfulResponse, $options, $finalPromise, $createStream, $fileManager);
            },
            function ($reason) use ($finalPromise): void {
                $finalPromise->reject($reason);
            }
        );

        if ($retryPromise instanceof CancellablePromiseInterface) {
            $finalPromise->setCancelHandler(fn() => $retryPromise->cancel());
        }

        return $finalPromise;
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

            if (!$mock->isPersistent()) {
                array_splice($mockedRequests, $match['index'], 1);
            }

            return $mock;
        };
    }

    /**
     * @param array<string, mixed> $options
     * @param CancellablePromise<Response|StreamingResponse|array<string, mixed>> $finalPromise
     */
    private function resolveRetryResponse(
        Response $successfulResponse,
        array $options,
        CancellablePromise $finalPromise,
        ?callable $createStream,
        ?FileManager $fileManager
    ): void {
        if (isset($options['download'])) {
            $this->resolveDownload($successfulResponse, $options, $finalPromise, $fileManager);
        } elseif (isset($options['stream']) && $options['stream'] === true) {
            $this->resolveStream($successfulResponse, $options, $finalPromise, $createStream);
        } else {
            $finalPromise->resolve($successfulResponse);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param CancellablePromise<Response|StreamingResponse|array<string, mixed>> $finalPromise
     */
    private function resolveDownload(
        Response $successfulResponse,
        array $options,
        CancellablePromise $finalPromise,
        ?FileManager $fileManager
    ): void {
        $destPath = is_string($options['download']) 
            ? $options['download'] 
            : ($fileManager ? $fileManager->createTempFile() : sys_get_temp_dir() . '/download_' . uniqid());
            
        file_put_contents($destPath, $successfulResponse->body());
        
        $finalPromise->resolve([
            'file' => $destPath,
            'status' => $successfulResponse->status(),
            'headers' => $successfulResponse->headers(),
            'size' => strlen($successfulResponse->body()),
            'protocol_version' => '1.1',
        ]);
    }

    /**
     * @param array<string, mixed> $options
     * @param CancellablePromise<Response|StreamingResponse|array<string, mixed>> $finalPromise
     */
    private function resolveStream(
        Response $successfulResponse,
        array $options,
        CancellablePromise $finalPromise,
        ?callable $createStream
    ): void {
        $onChunkRaw = $options['on_chunk'] ?? $options['onChunk'] ?? null;
        $onChunk = is_callable($onChunkRaw) ? $onChunkRaw : null;
        $body = $successfulResponse->body();

        if ($onChunk !== null) {
            $onChunk($body);
        }

        $createStreamFn = $createStream ?? fn(string $b): StreamInterface => (new HttpHandler())->createStream($b);
        /** @var StreamInterface $stream */
        $stream = $createStreamFn($body);
        
        $finalPromise->resolve(
            new StreamingResponse($stream, $successfulResponse->status(), $successfulResponse->headers())
        );
    }
}