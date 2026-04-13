<?php

declare(strict_types=1);

namespace Hibla\HttpClient\ValueObjects;

/**
 * A configuration object for defining HTTP request retry behavior.
 */
class RetryConfig
{
    /**
     * Default HTTP status codes that typically represent transient failures.
     */
    private const array DEFAULT_STATUS_CODES = [
        408, // Request Timeout
        429, // Too Many Requests
        500, // Internal Server Error
        502, // Bad Gateway
        503, // Service Unavailable
        504, // Gateway Timeout
    ];

   /**
     * Default error substrings that indicate transport-level failures.
     */
    private const array DEFAULT_EXCEPTIONS = [
        'timeout',
        'Simulated timeout',
        'cURL error',
        'Operation timed out',
        'connection failed',
        'Connection refused',    
        'Connection reset',         
        'Failed to connect',      
        'Couldn\'t connect',       
        'Could not resolve host',
        'Resolving timed out',
        'Connection timed out',
        'SSL connection timeout',
        'Network is unreachable',   
    ];

    /**
     * @var array<int> Merged list of default and user-defined retryable HTTP status codes.
     */
    public readonly array $retryableStatusCodes;

    /**
     * @var array<string> Merged list of default and user-defined retryable error substrings.
     */
    public readonly array $retryableExceptions;

    /**
     * Initializes a new retry configuration instance.
     *
     * @param int $maxRetries The maximum number of times to retry a failed request.
     * @param float $baseDelay The initial delay in seconds before the first retry.
     * @param float $maxDelay The absolute maximum delay in seconds between retries.
     * @param float $backoffMultiplier The multiplier for exponential backoff.
     * @param bool $jitter Whether to apply a random jitter to the delay.
     * @param array<int>|null $retryableStatusCodes Custom HTTP status codes to trigger a retry.
     * @param array<string>|null $retryableExceptions Custom error message substrings to trigger a retry.
     */
    public function __construct(
        public readonly int $maxRetries = 3,
        public readonly float $baseDelay = 1.0,
        public readonly float $maxDelay = 60.0,
        public readonly float $backoffMultiplier = 2.0,
        public readonly bool $jitter = true,
        ?array $retryableStatusCodes = null,
        ?array $retryableExceptions = null
    ) {
        $this->retryableStatusCodes = $retryableStatusCodes !== null
            ? \array_values(\array_unique($retryableStatusCodes))
            : self::DEFAULT_STATUS_CODES;

        $this->retryableExceptions = $retryableExceptions !== null
            ? \array_values(\array_unique($retryableExceptions))
            : self::DEFAULT_EXCEPTIONS;
    }

    /**
     * Determines if a retry should be attempted based on the current state.
     */
    public function shouldRetry(int $attempt, ?int $statusCode = null, ?string $error = null): bool
    {
        if ($attempt > $this->maxRetries) {
            return false;
        }

        if ($statusCode !== null && \in_array($statusCode, $this->retryableStatusCodes, true)) {
            return true;
        }

        if ($error !== null && $this->isRetryableError($error)) {
            return true;
        }

        return false;
    }

    /**
     * Calculates the delay in seconds for the next retry attempt.
     */
    public function getDelay(int $attempt): float
    {
        $delay = $this->baseDelay * \pow($this->backoffMultiplier, $attempt - 1);
        $delay = \min($delay, $this->maxDelay);

        if ($this->jitter) {
            $jitterRange = $delay * 0.25;
            $minJitter = (int) (-$jitterRange * 100);
            $maxJitter = (int) ($jitterRange * 100);
            $delay += \mt_rand($minJitter, $maxJitter) / 100;
        }

        return \max(0, $delay);
    }

    /**
     * Checks if an error message matches any of the retryable exception strings.
     */
    public function isRetryableError(string $error): bool
    {
        foreach ($this->retryableExceptions as $retryableError) {
            if (\stripos($error, $retryableError) !== false) {
                return true;
            }
        }

        return false;
    }
}
