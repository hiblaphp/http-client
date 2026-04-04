<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces;

/**
 * Extends ResponseInterface for Server-Sent Events connections.
 *
 * SSE responses are long-lived streams where the server pushes discrete
 * events.
 */
interface SSEResponseInterface extends ResponseInterface
{
    /**
     * Close the SSE connection and release all underlying resources.
     *
     * Safe to call multiple times — subsequent calls are no-ops.
     */
    public function close(): void;

    /**
     * Return the ID of the last successfully processed event.
     *
     * Used to populate the Last-Event-ID header on reconnect so the
     * server can resume the stream from the correct position.
     * Returns null before any event with an id field has been received.
     */
    public function getLastEventId(): ?string;
}
