<?php

namespace Hibla\HttpClient\SSE;

/**
 * Represents a single Server-Sent Event.
 */
class SSEEvent
{
    /**
     * Constructs a new SSEEvent instance.
     *
     * @param string|null $id The event ID.
     * @param string|null $event The event type.
     * @param string|null $data The event payload.
     * @param int|null $retry The server-advised reconnection time in milliseconds.
     * @param array<string, list<string>> $rawFields All raw fields parsed from the event.
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $event = null,
        public readonly ?string $data = null,
        public readonly ?int $retry = null,
        public readonly array $rawFields = []
    ) {
    }

    /**
     * Checks if this is a comment or an empty keep-alive event.
     */
    public function isKeepAlive(): bool
    {
        return $this->data === null || trim($this->data) === '';
    }

    /**
     * Gets the event type, defaulting to 'message' if not specified.
     */
    public function getType(): string
    {
        return $this->event ?? 'message';
    }

    /**
     * Converts the event to an array representation.
     *
     * @return array{id: string|null, event: string|null, data: string|null, retry: int|null, raw_fields: array<string, list<string>>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'data' => $this->data,
            'retry' => $this->retry,
            'raw_fields' => $this->rawFields,
        ];
    }
}
