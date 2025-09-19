<?php

namespace Hibla\Http\SSE;

/**
 * Represents a single Server-Sent Event.
 */
class SSEEvent
{
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?string $event = null,
        public readonly ?string $data = null,
        public readonly ?int $retry = null,
        public readonly array $rawFields = []
    ) {}

    /**
     * Check if this is a connection keep-alive event (empty data).
     */
    public function isKeepAlive(): bool
    {
        return $this->data === null || trim($this->data) === '';
    }

    /**
     * Get the event type, defaulting to 'message' if not specified.
     */
    public function getType(): string
    {
        return $this->event ?? 'message';
    }

    /**
     * Convert to array representation.
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
