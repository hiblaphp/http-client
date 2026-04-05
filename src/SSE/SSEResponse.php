<?php

declare(strict_types=1);

namespace Hibla\HttpClient\SSE;

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Interfaces\SSEResponseInterface;
use Hibla\HttpClient\Interfaces\StreamInterface;
use Hibla\HttpClient\Response;

/**
 * Represents an SSE response with event parsing capabilities.
 *
 * Extends Response rather than StreamingResponse intentionally — the
 * underlying stream is an internal implementation detail consumed by
 * SSEHandler via parseEvents(). Callers interact through onEvent
 * callbacks and SSEControl, not through stream primitives directly.
 */
class SSEResponse extends Response implements SSEResponseInterface
{
    private ?string $lastEventId = null;

    private ?string $requestId = null;

    private SSEParser $parser;

    /**
     * @param StreamInterface $stream The live SSE stream.
     * @param int $statusCode HTTP status code.
     * @param array<string, string|string[]> $headers Response headers.
     * @param string|null $requestId  Event loop request ID for this connection.
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
     * @inheritDoc
     *
     * Cancels the underlying cURL request before closing the stream.
     * Safe to call multiple times — subsequent calls are no-ops.
     */
    public function close(): void
    {
        if ($this->requestId !== null) {
            Loop::cancelCurlRequest($this->requestId);
            $this->requestId = null;
        }

        $this->getBody()->close();
    }

    /**
     * @inheritDoc
     */
    public function getLastEventId(): ?string
    {
        return $this->lastEventId;
    }

    /**
     * Sets the request ID for this SSE connection.
     *
     * @internal Called by SSEHandler only after the cURL request is registered.
     */
    public function setRequestId(?string $requestId): void
    {
        $this->requestId = $requestId;
    }

    /**
     * Parse an incoming SSE data chunk and yield discrete events.
     * Each yielded event updates the
     * last event ID if the server supplied one.
     *
     * @internal
     *
     * @param  string  $chunk  Raw SSE data chunk from the transport layer.
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
