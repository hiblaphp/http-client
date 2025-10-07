<?php

namespace Hibla\HttpClient\SSE;

use Exception;

/**
 * Configuration for SSE reconnection behavior.
 */
class SSEReconnectConfig
{
    /**
     * Constructs the reconnection configuration.
     *
     * @param bool $enabled Toggles reconnection on or off.
     * @param int $maxAttempts The maximum number of times to try reconnecting.
     * @param float $initialDelay The initial delay in seconds before the first reconnect attempt.
     * @param float $maxDelay The maximum delay in seconds between reconnection attempts.
     * @param float $backoffMultiplier The multiplier for exponential backoff.
     * @param bool $jitter Toggles random jitter to prevent stampeding herd issues.
     * @param list<string> $retryableErrors A list of error message substrings that are considered retryable.
     * @param callable|null $onReconnect A callback to execute when a reconnection attempt is about to be made.
     * @param callable(Exception):bool|null $shouldReconnect A custom callback to decide if a reconnection should be attempted for a given error.
     */
    public function __construct(
        public readonly bool $enabled = true,
        public readonly int $maxAttempts = 10,
        public readonly float $initialDelay = 1.0,
        public readonly float $maxDelay = 30.0,
        public readonly float $backoffMultiplier = 2.0,
        public readonly bool $jitter = true,
        public readonly array $retryableErrors = [
            'Connection refused',
            'Connection reset',
            'Connection timed out',
            'Could not resolve host',
            'Resolving timed out',
            'SSL connection timeout',
            'Operation timed out',
            'Network is unreachable',
        ],
        public readonly mixed $onReconnect = null,
        public readonly mixed $shouldReconnect = null,
    ) {
    }

    /**
     * Calculates the reconnection delay with exponential backoff and optional jitter.
     */
    public function calculateDelay(int $attempt): float
    {
        $delay = min(
            $this->initialDelay * pow($this->backoffMultiplier, $attempt - 1),
            $this->maxDelay
        );

        if ($this->jitter) {
            $delay *= 1.0 - mt_rand() / mt_getrandmax() * 0.5;
        }

        return $delay;
    }

    /**
     * Determines if an error is retryable based on configuration.
     */
    public function isRetryableError(Exception $error): bool
    {
        if (is_callable($this->shouldReconnect)) {
            return (bool) call_user_func($this->shouldReconnect, $error);
        }

        $message = $error->getMessage();
        foreach ($this->retryableErrors as $retryableError) {
            if (str_contains($message, $retryableError)) {
                return true;
            }
        }

        return false;
    }
}
