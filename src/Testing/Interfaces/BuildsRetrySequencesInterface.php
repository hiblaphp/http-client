<?php

namespace Hibla\HttpClient\Testing\Interfaces;

interface BuildsRetrySequencesInterface
{
    /**
     * Create multiple mocks that fail until the specified attempt succeeds.
     */
    public function failUntilAttempt(int $successAttempt, string $failureError = 'Connection failed'): static;

    /**
     * Create multiple mocks with different failure types until success.
     *
     * @param array<int, string|array{error?: string, retryable?: bool, delay?: float, status?: int}> $failures
     * @param string|array<string, mixed>|null $successResponse
     */
    public function failWithSequence(array $failures, string|array|null $successResponse = null): static;

    /**
     * Create timeout failures until success.
     */
    public function timeoutUntilAttempt(int $successAttempt, float $timeoutAfter = 5.0): static;

    /**
     * Create HTTP status code failures until success.
     */
    public function statusFailuresUntilAttempt(int $successAttempt, int $failureStatus = 500): static;

    /**
     * Create a mixed sequence of different failure types.
     */
    public function mixedFailuresUntilAttempt(int $successAttempt): static;

    /**
     * Create intermittent failures (some succeed, some fail).
     *
     * @param array<int, bool> $pattern Array of booleans (true = fail, false = succeed)
     */
    public function intermittentFailures(array $pattern): static;
}
