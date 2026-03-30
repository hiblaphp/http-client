<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Handler;

use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Contract for establishing and managing Server-Sent Events connections.
 *
 * SSE connections are long-lived HTTP responses where the server pushes
 * discrete events to the client over a persistent stream. Implementations
 * must parse the event-stream format (id, event, data, retry fields) and
 * invoke the appropriate callbacks as events arrive.
 *
 * When a SSEReconnectConfig is provided with reconnection enabled,
 * the implementation is responsible for re-establishing the connection
 * on failure and forwarding the Last-Event-ID header so the server
 * can resume from the correct position.
 */
interface SSEHandlerInterface
{
    /**
     * Open an SSE connection to $url and return a promise that resolves
     * to an SSEResponse once the connection is established.
     *
     * @param  string $url The SSE endpoint URL.
     * @param  array<int|string, mixed> $options Transport-specific options produced
     * by TransportOptionsBuilderInterface::buildForSSE().
     * @param  (callable(SSEEvent): void)|null $onEvent Invoked for each successfully parsed event.
     * @param  (callable(string): void)|null $onError Invoked when a connection error occurs.
     * Receives a human-readable error description.
     * @param  SSEReconnectConfig|null $reconnectConfig When non-null and enabled, governs
     * automatic reconnection behaviour.
     * @return PromiseInterface<SSEResponse>
     */
    public function connect(
        string $url,
        array $options = [],
        ?callable $onEvent = null,
        ?callable $onError = null,
        ?SSEReconnectConfig $reconnectConfig = null,
    ): PromiseInterface;
}
