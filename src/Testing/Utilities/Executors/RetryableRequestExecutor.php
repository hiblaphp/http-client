<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Utilities\Executors;

use Hibla\HttpClient\Response;
use Hibla\HttpClient\StreamingResponse;
use Hibla\HttpClient\Testing\Exceptions\MockAssertionException;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\RequestMatcher;
use Hibla\HttpClient\Testing\Utilities\RequestRecorder;
use Hibla\HttpClient\Testing\Utilities\ResponseFactory;
use Hibla\HttpClient\Traits\StreamTrait;
use Hibla\HttpClient\ValueObjects\RetryConfig;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

class RetryableRequestExecutor
{
    use StreamTrait;

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
     * @return PromiseInterface<Response|StreamingResponse|array<string, mixed>>
     */
    public function executeWithRetry(
        string $url,
        array $curlOptions,
        RetryConfig $retryConfig,
        string $method,
        array &$mockedRequests
    ): PromiseInterface {
        /** @var Promise<Response|StreamingResponse|array<string, mixed>> $finalPromise */
        $finalPromise = new Promise();

        /** @var array<string, mixed> $stringKeyedOptions */
        $stringKeyedOptions = array_filter($curlOptions, 'is_string', ARRAY_FILTER_USE_KEY);

        $mockProvider = $this->createMockProvider($method, $url, $curlOptions, $mockedRequests);

        $retryPromise = $this->responseFactory->createRetryableMockedResponse($retryConfig, $mockProvider);

        $retryPromise->then(
            function (Response $successfulResponse) use ($stringKeyedOptions, $finalPromise): void {
                $this->resolveRetryResponse($successfulResponse, $stringKeyedOptions, $finalPromise);
            },
            function ($reason) use ($finalPromise): void {
                $finalPromise->reject($reason);
            }
        );

        $finalPromise->onCancel(fn () => $retryPromise->cancel());

        return $finalPromise;
    }

    /**
     * @param array<int|string, mixed> $curlOptions
     * @param list<MockedRequest> $mockedRequests
     */
    private function createMockProvider(
        string $method,
        string $url,
        array $curlOptions,
        array &$mockedRequests
    ): callable {
        $curlOnlyOptions = array_filter($curlOptions, 'is_int', ARRAY_FILTER_USE_KEY);

        return function (int $attemptNumber) use ($method, $url, $curlOptions, $curlOnlyOptions, &$mockedRequests): MockedRequest {
            $match = $this->requestMatcher->findMatchingMock($mockedRequests, $method, $url, $curlOnlyOptions);

            if ($match === null) {
                throw new MockAssertionException("No mock found for attempt #{$attemptNumber}: {$method} {$url}");
            }

            $mock = $match['mock'];

            $this->requestRecorder->recordRequest($method, $url, $curlOptions);

            if (! $mock->isPersistent()) {
                array_splice($mockedRequests, $match['index'], 1);
            }

            return $mock;
        };
    }

    /**
     * @param array<string, mixed> $options
     * @param Promise<Response|StreamingResponse|array<string, mixed>> $finalPromise
     */
    private function resolveRetryResponse(
        Response $successfulResponse,
        array $options,
        Promise $finalPromise
    ): void {
        if (isset($options['download'])) {
            $this->resolveDownload($successfulResponse, $options, $finalPromise);
        } elseif (isset($options['stream']) && $options['stream'] === true) {
            $this->resolveStream($successfulResponse, $options, $finalPromise);
        } else {
            $finalPromise->resolve($successfulResponse);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param Promise<Response|StreamingResponse|array<string, mixed>> $finalPromise
     */
    private function resolveDownload(
        Response $successfulResponse,
        array $options,
        Promise $finalPromise
    ): void {
        $destPath = \is_string($options['download'])
            ? $options['download']
            : sys_get_temp_dir() . '/download_' . uniqid() . '.tmp';

        file_put_contents($destPath, $successfulResponse->body());

        $finalPromise->resolve([
            'file' => $destPath,
            'status' => $successfulResponse->status(),
            'headers' => $successfulResponse->headers(),
            'size' => \strlen($successfulResponse->body()),
            'protocol_version' => $successfulResponse->getHttpVersion() ?? '1.1',
        ]);
    }

    /**
     * @param array<string, mixed> $options
     * @param Promise<Response|StreamingResponse|array<string, mixed>> $finalPromise
     */
    private function resolveStream(
        Response $successfulResponse,
        array $options,
        Promise $finalPromise
    ): void {
        $onChunkRaw = $options['on_chunk'] ?? $options['onChunk'] ?? null;
        $onChunk = is_callable($onChunkRaw) ? $onChunkRaw : null;
        $body = $successfulResponse->body();

        if ($onChunk !== null) {
            $onChunk($body);
        }

        $stream = $this->createStream($body);

        $finalPromise->resolve(
            new StreamingResponse($stream, $successfulResponse->status(), $successfulResponse->headers())
        );
    }
}
