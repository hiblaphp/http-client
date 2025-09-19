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
    private array $headers;
    private ?string $httpVersion = null;
    private string $buffer = '';
    private ?string $lastEventId = null;

    public function __construct(Stream $stream, int $statusCode = 200, array $headers = [])
    {
        $this->stream = $stream;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    public function getStream(): Stream
    {
        return $this->stream;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHttpVersion(): ?string
    {
        return $this->httpVersion;
    }

    public function setHttpVersion(?string $httpVersion): void
    {
        $this->httpVersion = $httpVersion;
    }

    public function getLastEventId(): ?string
    {
        return $this->lastEventId;
    }

    /**
     * Parse incoming SSE data and yield events.
     *
     * @param  string  $chunk  Raw SSE data chunk
     * @return \Generator<SSEEvent>
     */
    public function parseEvents(string $chunk): \Generator
    {
        $this->buffer .= $chunk;

        // Split on double newlines (event boundaries)
        $parts = preg_split('/\r?\n\r?\n/', $this->buffer, -1, PREG_SPLIT_NO_EMPTY);

        // Keep the last part in buffer if it doesn't end with double newline
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
     * Parse a single SSE event from raw data.
     */
    private function parseEvent(string $eventData): ?SSEEvent
    {
        $lines = preg_split('/\r?\n/', trim($eventData));
        $fields = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, ':')) {
                // Comment line, skip
                continue;
            }

            if (str_contains($line, ':')) {
                [$field, $value] = explode(':', $line, 2);
                $field = trim($field);
                $value = ltrim($value, ' '); // Only left trim spaces after colon
            } else {
                $field = trim($line);
                $value = '';
            }

            if ($field === '') {
                continue;
            }

            // Handle multiple data fields (concatenated with newlines)
            if ($field === 'data') {
                if (isset($fields['data'])) {
                    $fields['data'] .= "\n".$value;
                } else {
                    $fields['data'] = $value;
                }
            } else {
                $fields[$field] = $value;
            }
        }

        // Skip empty events
        if (empty($fields)) {
            return null;
        }

        return new SSEEvent(
            id: $fields['id'] ?? null,
            event: $fields['event'] ?? null,
            data: $fields['data'] ?? null,
            retry: isset($fields['retry']) && is_numeric($fields['retry'])
                ? (int) $fields['retry']
                : null,
            rawFields: $fields
        );
    }

    /**
     * Get the next available events from the stream.
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

        // Process any remaining buffer
        if ($this->buffer !== '') {
            $event = $this->parseEvent($this->buffer);
            if ($event !== null) {
                yield $event;
            }
        }
    }
}
