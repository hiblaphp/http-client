<?php

namespace Hibla\Http\Testing\Interfaces;

interface BuildsAdvancedScenariosInterface
{
    /**
     * Create gradually improving response times (simulate network recovery).
     */
    public function slowlyImproveUntilAttempt(int $successAttempt, float $maxDelay = 10.0): static;

    /**
     * Simulate rate limiting with exponential backoff.
     */
    public function rateLimitedUntilAttempt(int $successAttempt): static;
}
