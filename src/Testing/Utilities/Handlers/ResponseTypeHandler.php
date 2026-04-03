<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Utilities\Handlers;

use Hibla\HttpClient\Response;
use Hibla\HttpClient\StreamingResponse;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\FileManager;
use Hibla\HttpClient\Testing\Utilities\ResponseFactory;
use Hibla\HttpClient\Traits\StreamTrait;
use Hibla\Promise\Interfaces\PromiseInterface;
use Psr\Http\Message\StreamInterface;

class ResponseTypeHandler
{
    use StreamTrait;

    private ResponseFactory $responseFactory;
    private FileManager $fileManager;

    public function __construct(
        ResponseFactory $responseFactory,
        FileManager $fileManager,
    ) {
        $this->responseFactory = $responseFactory;
        $this->fileManager = $fileManager;
    }

    /**
     * @param array{mock: MockedRequest, index: int} $match
     * @param array<string, mixed> $options
     * @param list<MockedRequest> $mockedRequests
     * @return PromiseInterface<Response>|PromiseInterface<StreamingResponse>|PromiseInterface<array<string, mixed>>
     */
    public function handleMockedResponse(
        array $match,
        array $options,
        array &$mockedRequests,
        string $url,
        string $method,
        ?callable $createStream = null
    ): PromiseInterface {
        $mock = $match['mock'];

        if (! $mock->isPersistent()) {
            array_splice($mockedRequests, $match['index'], 1);
        }

        if (isset($options['upload'])) {
            return $this->handleUpload($mock, $options, $url);
        }

        if (isset($options['download'])) {
            return $this->handleDownload($mock, $options);
        }

        if (isset($options['stream']) && $options['stream'] === true) {
            return $this->handleStream($mock, $options, $createStream);
        }

        return $this->handleStandardResponse($mock);
    }

    private function handleUpload(MockedRequest $mock, array $options, string $url): PromiseInterface
    {
        $source = \is_string($options['upload']) ? $options['upload'] : '';
        $onProgress = isset($options['on_progress']) && is_callable($options['on_progress']) ? $options['on_progress'] : null;

        if ($source === '') {
            throw new \InvalidArgumentException('Upload source must be a non-empty string');
        }

        return $this->responseFactory->createMockedUpload($mock, $source, $url, $onProgress);
    }

    /**
     * @param array<string, mixed> $options
     * @return PromiseInterface<array<string, mixed>>
     */
    private function handleDownload(MockedRequest $mock, array $options): PromiseInterface
    {
        $destination = \is_string($options['download']) ? $options['download'] : '';
        $onProgress = isset($options['on_progress']) && is_callable($options['on_progress']) ? $options['on_progress'] : null;

        if ($destination === '') {
            throw new \InvalidArgumentException('Download destination must be a non-empty string');
        }

        return $this->responseFactory->createMockedDownload($mock, $destination, $this->fileManager, $onProgress);
    }

    /**
     * @param array<string, mixed> $options
     * @return PromiseInterface<StreamingResponse>
     */
    private function handleStream(MockedRequest $mock, array $options, ?callable $createStream): PromiseInterface
    {
        $onChunkRaw = $options['on_chunk'] ?? $options['onChunk'] ?? null;
        $onChunk = is_callable($onChunkRaw) ? $onChunkRaw : null;

        $createStreamFn = $createStream ?? fn (string $body): StreamInterface => $this->createStream($body);

        return $this->responseFactory->createMockedStream($mock, $onChunk, $createStreamFn);
    }

    /**
     * @return PromiseInterface<Response>
     */
    private function handleStandardResponse(
        MockedRequest $mock,
    ): PromiseInterface {
        $responsePromise = $this->responseFactory->createMockedResponse($mock);

        $mappedPromise = $responsePromise->then(fn (Response $response): Response => $response);

        $mappedPromise->onCancel($responsePromise->cancel(...));

        return $mappedPromise;
    }
}
