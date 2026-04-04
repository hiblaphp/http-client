<?php

declare(strict_types=1);

namespace Hibla\HttpClient\SSE;

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Interfaces\SSEResponseInterface;
use Hibla\HttpClient\Interfaces\StreamInterface;
use Hibla\HttpClient\StreamingResponse;

/**
 * Represents an SSE streaming response with event parsing capabilities.
 */
class SSEResponse extends StreamingResponse implements SSEResponseInterface
{
    private ?string $lastEventId = null;

    private ?string $requestId = null;

    private SSEParser $parser;

    /**
     * @param StreamInterface $stream
     * @param int $statusCode
     * @param array<string, string|string[]> $headers
     * @param string|null $requestId The event loop request ID for this SSE connection
     */
    public function __construct(
        StreamInterface $stream,
        int $statusCode = 200,
        array $headers = [],
        ?string $requestId = null,
    ) {
        parent::__construct($stream, $statusCode, $headers);
        $this->requestId = $requestId;
        $this->parser = new SSEParser();
    }

    /**
     * Sets the request ID for this SSE connection.
     *
     * @internal Called by SSEBuilder::connect() only.
     */
    public function setRequestId(?string $requestId): void
    {
        $this->requestId = $requestId;
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        if ($this->requestId !== null) {
            Loop::cancelCurlRequest($this->requestId);
            $this->requestId = null;
        }

        $this->getStream()->close();
    }

    /**
     * @inheritDoc
     */
    public function getStream(): StreamInterface
    {
        return parent::getStream();
    }

    /**
     * @inheritDoc
     */
    public function getLastEventId(): ?string
    {
        return $this->lastEventId;
    }

    /**
     * Parses incoming SSE data chunks and yields events.
     *
     * @internal
     *
     * @param  string  $chunk  Raw SSE data chunk.
     * @return \Generator<SSEEvent>
     */
    public function parseEvents(string $chunk): \Generator
    {
        foreach ($this->parser->parse($chunk) as $event) {
            if ($event->id !== null) {
                $this->lastEventId = $event->id;
            }

            yield $event;
        }
    }
}
