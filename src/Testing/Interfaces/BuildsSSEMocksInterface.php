<?php

declare(strict_types=1);

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

    /**
     * Mock an SSE stream that emits a specific list of events.
     * 
     * Use ->dataStreamTransferLatency() to control the timing between events.
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $events
     */
    public function sseWithPeriodicEvents(array $events): static;

    /**
     * Mock an SSE stream that emits a limited number of generated events then closes.
     * 
     * Use ->dataStreamTransferLatency() to control the timing between events.
     *
     * @param int $eventCount Number of events to send.
     * @param callable|null $eventGenerator Callback to generate event data: fn(int $index) => array
     */
    public function sseWithLimitedEvents(int $eventCount, ?callable $eventGenerator = null): static;

    /**
     * Mock an infinite SSE stream (emits until the client cancels).
     * 
     * Use ->dataStreamTransferLatency() to control the timing between events.
     *
     * @param callable $eventGenerator Callback to generate events: fn(int $index) => array
     * @param int|null $maxEvents Optional maximum events to send before stopping.
     */
    public function sseInfiniteStream(callable $eventGenerator, ?int $maxEvents = null): static;

    /**
     * Mock an SSE stream that emits N events and then simulates a network drop.
     *
     * @param int $eventCount Number of events before disconnect.
     * @param string $disconnectError Error message on disconnect.
     * @param callable|null $eventGenerator
     */
    public function ssePeriodicThenDisconnect(
        int $eventCount,
        string $disconnectError = 'Connection lost',
        ?callable $eventGenerator = null
    ): static;
}