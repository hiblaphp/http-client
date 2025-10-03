<?php

namespace Hibla\Http\SSE;

use Hibla\Http\Stream;

/**
 * Represents an SSE streaming response with event parsing capabilities.
 */
class SSEResponse
{
    private Stream $stream;
    private int $statusCode;
    /**
     * @var array<string, mixed>
     */
    private array $headers;
    private ?string $httpVersion = null;
    private string $buffer = '';
    private ?string $lastEventId = null;

    /**
     * Constructs the SSEResponse.
     *
     * @param array<string, mixed> $headers
     */
    public function __construct(Stream $stream, int $statusCode = 200, array $headers = [])
    {
        $this->stream = $stream;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    /**
     * Gets the underlying stream.
     */
    public function getStream(): Stream
    {
        return $this->stream;
    }

    /**
     * Gets the HTTP status code.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Gets the response headers.
     *
     * @return array<string, mixed>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Gets the HTTP protocol version.
     */
    public function getHttpVersion(): ?string
    {
        return $this->httpVersion;
    }

    /**
     * Sets the HTTP protocol version.
     */
    public function setHttpVersion(?string $httpVersion): void
    {
        $this->httpVersion = $httpVersion;
    }

    /**
     * Gets the ID of the last processed event.
     */
    public function getLastEventId(): ?string
    {
        return $this->lastEventId;
    }

    /**
     * Parses incoming SSE data chunks and yields events.
     *
     * @param  string  $chunk  Raw SSE data chunk.
     * @return \Generator<SSEEvent>
     */
    public function parseEvents(string $chunk): \Generator
    {
        $this->buffer .= $chunk;

        $parts = preg_split('/\r?\n\r?\n/', $this->buffer, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) { 
            $this->buffer = ''; 
            return;
        }

        if (! str_ends_with($this->buffer, "\n\n") && ! str_ends_with($this->buffer, "\r\n\r\n")) {
            $this->buffer = array_pop($parts) ?? '';
        } else {
            $this->buffer = '';
        }

        foreach ($parts as $eventData) {
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
        $lines = preg_split('/\r?\n/', trim($eventData));
        if ($lines === false) {
            return null; 
        }
        
        /** @var array<string, list<string>> $fields */
        $fields = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, ':')) {
                continue;
            }

            if (str_contains($line, ':')) {
                [$field, $value] = explode(':', $line, 2);
                $value = ltrim($value); 
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

    /**
     * Gets a generator that yields all available events from the stream.
     *
     * @return \Generator<SSEEvent>
     */
    public function getEvents(): \Generator
    {
        while (! $this->stream->eof()) {
            $chunk = $this->stream->read(8192);
            if ($chunk === '') {
                break;
            }

            yield from $this->parseEvents($chunk);
        }

        if ($this->buffer !== '') {
            $event = $this->parseEvent($this->buffer);
            if ($event !== null) {
                yield $event;
            }
        }
    }
}