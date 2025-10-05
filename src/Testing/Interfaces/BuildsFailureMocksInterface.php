<?php

namespace Hibla\Http\Testing\Interfaces;

interface BuildsFailureMocksInterface
{
    /**
     * Make the mock fail with an error.
     */
    public function fail(string $error = 'Mocked request failure'): static;

    /**
     * Simulate a timeout failure.
     */
    public function timeout(float $seconds = 30.0): static;

    /**
     * Simulate a timeout failure that can be retried.
     */
    public function timeoutFailure(float $timeoutAfter = 30.0, ?string $customMessage = null): static;

    /**
     * Simulate a retryable failure.
     */
    public function retryableFailure(string $error = 'Connection failed'): static;

    /**
     * Simulate a network error.
     */
    public function networkError(string $errorType = 'connection'): static;
}
