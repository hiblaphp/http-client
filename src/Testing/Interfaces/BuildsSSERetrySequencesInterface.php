<?php

namespace Hibla\HttpClient\Testing\Interfaces;

interface BuildsSSERetrySequencesInterface
{
    /**
     * SSE connection that fails until the specified attempt succeeds.
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $successEvents
     */
    public function sseFailUntilAttempt(
        int $successAttempt,
        array $successEvents = [],
        string $failureError = 'SSE Connection failed'
    ): static;

    /**
     * SSE connection with a sequence of different failure types.
     *
     * @param array<int, string|array{error?: string, retryable?: bool, delay?: float}> $failures
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $successEvents
     */
    public function sseFailWithSequence(array $failures, array $successEvents = []): static;

    /**
     * SSE connection that times out until success.
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $successEvents
     */
    public function sseTimeoutUntilAttempt(
        int $successAttempt,
        array $successEvents = [],
        float $timeoutAfter = 5.0
    ): static;

    /**
     * SSE connection with intermittent failures.
     *
     * @param array<int, bool> $pattern Array of booleans (true = fail, false = succeed)
     */
    public function sseIntermittentFailures(array $pattern): static;

    /**
     * SSE connection with network error types until success.
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $successEvents
     */
    public function sseNetworkErrorsUntilAttempt(
        int $successAttempt,
        array $successEvents = []
    ): static;

    /**
     * SSE connection that gradually improves (simulates network recovery).
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $successEvents
     */
    public function sseSlowlyImproveUntilAttempt(
        int $successAttempt,
        array $successEvents = [],
        float $maxDelay = 10.0
    ): static;

    /**
     * SSE connection that drops after receiving some events (then needs reconnection).
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $eventsBeforeDrop
     */
    public function sseDropAfterEvents(
        array $eventsBeforeDrop,
        string $dropError = 'Connection lost',
        bool $retryable = true
    ): static;

    /**
     * SSE reconnection scenario: mock resumption from a specific event ID.
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $eventsAfterResume
     */
    public function sseReconnectFromEventId(
        string $lastEventId,
        array $eventsAfterResume
    ): static;

    /**
     * SSE connection with mixed failure types (timeout, network errors, etc.).
     */
    public function sseMixedFailuresUntilAttempt(int $successAttempt): static;

    /**
     * SSE with rate limiting (429 status) until success.
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $successEvents
     */
    public function sseRateLimitedUntilAttempt(
        int $successAttempt,
        array $successEvents = []
    ): static;
}
