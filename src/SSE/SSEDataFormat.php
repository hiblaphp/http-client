<?php

declare(strict_types=1);

namespace Hibla\HttpClient\SSE;

enum SSEDataFormat: string
{
    /**
     * Full SSEEvent object (default).
     * Use when you need typed access to all event properties.
     */
    case Event = 'event';

    /**
     * The entire event as a plain array via SSEEvent::toArray(),
     * with the data field automatically decoded from JSON if valid.
     * Use when you want all event fields without working with the SSEEvent object.
     */
    case Array = 'array';

    /**
     * Only the data field, decoded as JSON.
     * Falls back to a raw string if the data is not valid JSON.
     * Use when you only care about the event payload.
     */
    case Json = 'json';

    /**
     * Only the data field, as a raw unprocessed string.
     * Use when you need to handle deserialization yourself.
     */
    case Raw = 'raw';
}