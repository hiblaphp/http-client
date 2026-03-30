<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces;

use Hibla\HttpClient\SSE\SSEEvent;

/**
 * Extends StreamingResponseInterface for Server-Sent Events connections.
 *
 * SSE responses are long-lived streams where the server pushes discrete
 * events. This interface adds event parsing and connection lifecycle
 * control on top of the base streaming contract.
 */
interface SSEResponseInterface extends StreamingResponseInterface
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

    /**
     * Yield all complete events available from the stream until it closes.
     *
     * Reads the underlying stream to exhaustion. This is a blocking
     * generator — it will not return until the connection is closed
     * or the stream signals EOF.
     *
     * @return \Generator<SSEEvent>
     */
    public function getEvents(): \Generator;
}