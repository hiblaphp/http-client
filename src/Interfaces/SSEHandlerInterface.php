<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces;

use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Interface for handling Server-Sent Events (SSE) connections.
 */
interface SSEHandlerInterface
{
    /**
     * Creates an SSE connection with optional reconnection logic.
     *
     * @param string $url The SSE endpoint URL
     * @param array<int|string, mixed> $options Request configuration options for SSE connection.
     *                                           Implementations may accept various options such as:
     *                                           - Custom headers (Accept, Cache-Control, etc.)
     *                                           - Authentication credentials
     *                                           - Timeout settings
     *                                           - Connection keep-alive configuration
     *                                           - Custom implementation-specific options
     * @param callable(SSEEvent): void|null $onEvent Optional callback invoked for each SSE event received.
     *                                                Signature: function(SSEEvent $event): void
     * @param callable(string): void|null $onError Optional callback invoked when connection errors occur.
     *                                              Signature: function(string $error): void
     * @param SSEReconnectConfig|null $reconnectConfig Optional configuration for automatic reconnection behavior.
     *                                                  If provided with enabled=true, the handler will
     *                                                  automatically attempt to reconnect on connection failures.
     *
     * @return PromiseInterface<SSEResponse> A promise that resolves to an SSEResponse when the connection
     *                                        is established, or rejects with HttpStreamException,
     *                                        NetworkException, or RequestException on failure
     */
    public function connect(
        string $url,
        array $options = [],
        ?callable $onEvent = null,
        ?callable $onError = null,
        ?SSEReconnectConfig $reconnectConfig = null
    ): PromiseInterface;
}
