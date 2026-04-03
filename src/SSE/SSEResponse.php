<?php

declare(strict_types=1);

namespace Hibla\HttpClient\SSE;

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Interfaces\SSEResponseInterface;
use Hibla\HttpClient\Stream;
use Hibla\HttpClient\StreamingResponse;
use Psr\Http\Message\StreamInterface;

/**
 * Represents an SSE streaming response with event parsing capabilities.
 */
class SSEResponse extends StreamingResponse implements SSEResponseInterface
{
    private string $buffer = '';

    private ?string $lastEventId = null;

    private ?string $requestId = null;

    /**
     * @param Stream $stream
     * @param int $statusCode
     * @param array<string, string|string[]> $headers
     * @param string|null $requestId The event loop request ID for this SSE connection
     */
    public function __construct(Stream $stream, int $statusCode = 200, array $headers = [], ?string $requestId = null)
    {
        parent::__construct($stream, $statusCode, $headers);
        $this->requestId = $requestId;
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
        $this->buffer .= $chunk;

        $normalized = str_replace("\r\n", "\n", $this->buffer);
        $parts = explode("\n\n", $normalized);

        if (! str_ends_with($normalized, "\n\n")) {
            $this->buffer = array_pop($parts) ?? '';
        } else {
            $this->buffer = '';
        }

        foreach ($parts as $eventData) {
            if ($eventData === '') {
                continue;
            }

            $event = $this->parseEvent($eventData);
            if ($event !== null) {
                if ($event->id !== null) {
                    $this->lastEventId = $event->id;
                }
                yield $event;
            }
        }
    }

    /**
     * Parses a single SSE event from a raw data block.
     */
    private function parseEvent(string $eventData): ?SSEEvent
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $eventData));

        /** @var array<string, list<string>> $fields */
        $fields = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, ':')) {
                continue;
            }

            $colonPos = strpos($line, ':');
            if ($colonPos !== false) {
                $field = substr($line, 0, $colonPos);
                $value = substr($line, $colonPos + 1);
                if (str_starts_with($value, ' ')) {
                    $value = substr($value, 1);
                }
            } else {
                $field = $line;
                $value = '';
            }

            $field = trim($field);
            if ($field === '') {
                continue;
            }

            $fields[$field][] = $value;
        }

        if ($fields === []) {
            return null;
        }

        $idValues = $fields['id'] ?? [];
        $eventValues = $fields['event'] ?? [];
        $retryValues = $fields['retry'] ?? [];

        $id = end($idValues) !== false ? end($idValues) : null;
        $event = end($eventValues) !== false ? end($eventValues) : null;
        $retryValue = end($retryValues) !== false ? end($retryValues) : null;

        return new SSEEvent(
            id: $id,
            event: $event,
            data: implode("\n", $fields['data'] ?? []),
            retry: is_numeric($retryValue) ? (int) $retryValue : null,
            rawFields: $fields
        );
    }
}
