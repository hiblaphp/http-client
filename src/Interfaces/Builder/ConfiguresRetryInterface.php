<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Builder;

use Hibla\HttpClient\RetryConfig;

/**
 * Fluent interface for configuring automatic retry behaviour.
 *
 * Retry logic is applied at the transport level after all interceptors
 * have run. Only transient failures (network errors, configured status
 * codes) trigger a retry — 4xx client errors are never retried
 * automatically.
 */
interface ConfiguresRetryInterface
{
    /**
     * Enable automatic retries with exponential backoff.
     *
     * @param  int    $maxRetries        Number of retry attempts after the initial failure.
     * @param  float  $baseDelay         Seconds to wait before the first retry.
     * @param  float  $backoffMultiplier Multiplier applied to $baseDelay on each subsequent attempt.
     *                                  A value of 2.0 doubles the delay after each failure.
     */
    public function retry(
        int $maxRetries = 3,
        float $baseDelay = 1.0,
        float $backoffMultiplier = 2.0,
    ): static;

    /**
     * Enable retries from a fully configured RetryConfig value object.
     *
     * Prefer this over retry() when you need fine-grained control over
     * retryable status codes, error patterns, or jitter strategy.
     */
    public function retryWith(RetryConfig $config): static;

    /**
     * Disable automatic retries for this request.
     *
     * Useful for explicitly opting out when a global retry policy
     * has been configured at the handler level.
     */
    public function noRetry(): static;
}