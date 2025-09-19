<?php

namespace Hibla\Http;

/**
 * A configuration object for defining HTTP request retry behavior.
 *
 * This class encapsulates all parameters related to automatic retries,
 * including the number of attempts, backoff strategy, jitter, and the
 * conditions under which a retry should be triggered.
 */
class RetryConfig
{
    /**
     * Initializes a new retry configuration instance.
     *
     * @param  int  $maxRetries  The maximum number of times to retry a failed request.
     * @param  float  $baseDelay  The initial delay in seconds before the first retry.
     * @param  float  $maxDelay  The absolute maximum delay in seconds between retries.
     * @param  float  $backoffMultiplier  The multiplier for exponential backoff (e.g., 2.0 doubles the delay each time).
     * @param  bool  $jitter  Whether to apply a random jitter to the delay to prevent thundering herd issues.
     * @param  int[]  $retryableStatusCodes  A list of HTTP status codes that should trigger a retry.
     * @param  string[]  $retryableExceptions  A list of substrings. If a request's error message contains one of these, a retry will be attempted.
     */
    public function __construct(
        public readonly int $maxRetries = 3,
        public readonly float $baseDelay = 1.0,
        public readonly float $maxDelay = 60.0,
        public readonly float $backoffMultiplier = 2.0,
        public readonly bool $jitter = true,
        public readonly array $retryableStatusCodes = [408, 429, 500, 502, 503, 504],
        public readonly array $retryableExceptions = [
            'Simulated timeout',
            'cURL error',
            'timeout',
            'connection failed',
            'Could not resolve host',
            'Resolving timed out',
            'Connection timed out',
            'SSL connection timeout',
        ]
    ) {}

    /**
     * Determines if a retry should be attempted based on the current state.
     *
     * @param  int  $attempt  The current attempt number (e.g., 1 is the first attempt, 2 is the first retry).
     * @param  int|null  $statusCode  The HTTP status code of the failed response, if available.
     * @param  string|null  $error  The error message from the failed request, if available.
     * @return bool True if the request should be retried, false otherwise.
     */
    public function shouldRetry(int $attempt, ?int $statusCode = null, ?string $error = null): bool
    {
        if ($attempt > $this->maxRetries) {
            return false;
        }

        if ($statusCode !== null && in_array($statusCode, $this->retryableStatusCodes, true)) {
            return true;
        }

        if ($error !== null && $this->isRetryableError($error)) {
            return true;
        }

        return false;
    }

    /**
     * Calculates the delay in seconds for the next retry attempt.
     *
     * @param  int  $attempt  The current attempt number that has just failed.
     * @return float The calculated delay in seconds.
     */
    public function getDelay(int $attempt): float
    {
        $delay = $this->baseDelay * pow($this->backoffMultiplier, $attempt - 1);
        $delay = min($delay, $this->maxDelay);

        if ($this->jitter) {
            $jitterRange = $delay * 0.25;
            $minJitter = (int) (-$jitterRange * 100);
            $maxJitter = (int) ($jitterRange * 100);
            $delay += mt_rand($minJitter, $maxJitter) / 100;
        }

        return max(0, $delay);
    }

    /**
     * Checks if an error message matches any of the retryable exception strings.
     */
    public function isRetryableError(string $error): bool
    {
        foreach ($this->retryableExceptions as $retryableError) {
            if (stripos($error, $retryableError) !== false) {
                return true;
            }
        }

        return false;
    }
}
