<?php

namespace Hibla\HttpClient\Testing\Utilities\Handlers;

use Hibla\HttpClient\CacheConfig;
use Hibla\HttpClient\Handlers\HttpHandler;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\StreamingResponse;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\FileManager;
use Hibla\HttpClient\Testing\Utilities\ResponseFactory;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Psr\Http\Message\StreamInterface;

class ResponseTypeHandler
{
    private ResponseFactory $responseFactory;
    private FileManager $fileManager;
    private CacheHandler $cacheHandler;

    public function __construct(
        ResponseFactory $responseFactory,
        FileManager $fileManager,
        CacheHandler $cacheHandler
    ) {
        $this->responseFactory = $responseFactory;
        $this->fileManager = $fileManager;
        $this->cacheHandler = $cacheHandler;
    }

    /**
     * @param array{mock: MockedRequest, index: int} $match
     * @param array<string, mixed> $options
     * @param list<MockedRequest> $mockedRequests
     * @return PromiseInterface<Response>|CancellablePromiseInterface<StreamingResponse>|CancellablePromiseInterface<array<string, mixed>>
     */
    public function handleMockedResponse(
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
            return $this->handleDownload($mock, $options);
        }

        if (isset($options['stream']) && $options['stream'] === true) {
            return $this->handleStream($mock, $options, $createStream);
        }

        return $this->handleStandardResponse($mock, $cacheConfig, $url);
    }

    /**
     * @param array<string, mixed> $options
     * @return CancellablePromiseInterface<array<string, mixed>>
     */
    private function handleDownload(MockedRequest $mock, array $options): CancellablePromiseInterface
    {
        $destination = is_string($options['download']) ? $options['download'] : '';

        if ($destination === '') {
            throw new \InvalidArgumentException('Download destination must be a non-empty string');
        }

        // @phpstan-ignore-next-line
        return $this->responseFactory->createMockedDownload($mock, $destination, $this->fileManager);
    }

    /**
     * @param array<string, mixed> $options
     * @return CancellablePromiseInterface<StreamingResponse>
     */
    private function handleStream(MockedRequest $mock, array $options, ?callable $createStream): CancellablePromiseInterface
    {
        $onChunkRaw = $options['on_chunk'] ?? $options['onChunk'] ?? null;
        $onChunk = is_callable($onChunkRaw) ? $onChunkRaw : null;

        $createStreamFn = $createStream ?? fn (string $body): StreamInterface => (new HttpHandler())->createStream($body);

        return $this->responseFactory->createMockedStream($mock, $onChunk, $createStreamFn);
    }

    /**
     * @return PromiseInterface<Response>
     */
    private function handleStandardResponse(
        MockedRequest $mock,
        ?CacheConfig $cacheConfig,
        string $url
    ): PromiseInterface {
        $responsePromise = $this->responseFactory->createMockedResponse($mock);

        return $responsePromise->then(function (Response $response) use ($cacheConfig, $url) {
            $this->cacheHandler->cacheResponse($url, $response, $cacheConfig);

            return $response;
        });
    }
}
