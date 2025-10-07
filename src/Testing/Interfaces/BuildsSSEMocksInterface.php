<?php

namespace Hibla\HttpClient\Testing\Interfaces;

interface BuildsSSEMocksInterface
{
    /**
     * Configure this mock as an SSE response.
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int, comment?: string}> $events
     */
    public function respondWithSSE(array $events): static;

    /**
     * Add a single SSE event to the mock.
     */
    public function addSSEEvent(
        ?string $data = null,
        ?string $event = null,
        ?string $id = null,
        ?int $retry = null
    ): static;

    /**
     * Mock an SSE stream that sends keepalive events.
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $dataEvents
     */
    public function sseWithKeepalive(array $dataEvents, int $keepaliveCount = 3): static;

    /**
     * Mock an SSE stream that disconnects after a certain number of events.
     */
    public function sseDisconnectAfter(int $eventsBeforeDisconnect, string $disconnectError = 'Connection reset'): static;

    /**
     * Mock an SSE stream with custom retry interval.
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $events
     */
    public function sseWithRetry(array $events, int $retryMs = 3000): static;

    /**
     * Mock an SSE stream with multiple event types.
     *
     * @param array<string, array<int, string|array<string, mixed>>> $eventsByType
     */
    public function sseMultipleTypes(array $eventsByType): static;

    /**
     * Mock an SSE stream with event IDs (useful for reconnection scenarios).
     *
     * @param array<int, array{data?: string, event?: string, id: string, retry?: int}> $eventsWithIds
     */
    public function sseWithEventIds(array $eventsWithIds): static;

    /**
     * Mock an SSE stream that expects Last-Event-ID header (for resumption).
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $eventsAfterResume
     */
    public function sseExpectLastEventId(string $lastEventId, array $eventsAfterResume): static;

    /**
     * Mock an SSE stream with server-sent retry directive.
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $events
     */
    public function sseWithRetryDirective(int $retryMs, array $events = []): static;

    /**
     * Mock an SSE stream with comment lines (for testing parser).
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $events
     * @param array<int, string> $comments
     */
    public function sseWithComments(array $events, array $comments = []): static;

    /**
     * Mock an SSE stream that sends only keepalive (heartbeat) events.
     */
    public function sseHeartbeatOnly(int $heartbeatCount = 10): static;
}
