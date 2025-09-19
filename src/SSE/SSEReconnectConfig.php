<?php

namespace Hibla\Http\SSE;

use Exception;

/**
 * Configuration for SSE reconnection behavior.
 */
class SSEReconnectConfig
{
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
    ) {}

    /**
     * Calculate reconnection delay with exponential backoff and optional jitter.
     */
    public function calculateDelay(int $attempt): float
    {
        $delay = min(
            $this->initialDelay * pow($this->backoffMultiplier, $attempt - 1),
            $this->maxDelay
        );

        if ($this->jitter) {
            $delay *= 0.5 + mt_rand() / mt_getrandmax() * 0.5;
        }

        return $delay;
    }

    /**
     * Determine if an error is retryable.
     */
    public function isRetryableError(Exception $error): bool
    {
        if ($this->shouldReconnect !== null) {
            return call_user_func($this->shouldReconnect, $error);
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
