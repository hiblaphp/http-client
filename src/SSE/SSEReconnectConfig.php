<?php

declare(strict_types=1);

namespace Hibla\HttpClient\SSE;

use Exception;
use TypeError;

/**
 * Configuration for SSE reconnection behavior.
 */
class SSEReconnectConfig
{
    /**
     * The system-default list of error substrings that trigger a reconnection attempt.
     */
    private const array DEFAULT_RETRYABLE_ERRORS = [
        'Connection refused',
        'Connection reset',
        'Connection timed out',
        'Could not resolve host',
        'Resolving timed out',
        'SSL connection timeout',
        'Operation timed out',
        'Network is unreachable',
    ];

    /**
     * Default HTTP status codes that should trigger a reconnection.
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
     * @var array<string> A merged list of retryable error strings.
     */
    public readonly array $retryableErrors;

    /**
     * @var array<int> A merged list of retryable HTTP status codes.
     */
    public readonly array $retryableStatusCodes;

    /**
     * @var (callable(int, float, string|Exception): void)|null
     */
    public readonly mixed $onReconnect;

    /**
     * @var (callable(Exception): bool)|null
     */
    public readonly mixed $shouldReconnect;

    /**
     * Constructs the reconnection configuration.
     *
     * @param bool $enabled Toggles reconnection on or off.
     * @param int $maxAttempts The maximum number of times to try reconnecting.
     * @param float $initialDelay The initial delay in seconds before the first reconnect attempt.
     * @param float $maxDelay The maximum delay in seconds between reconnection attempts.
     * @param float $backoffMultiplier The multiplier for exponential backoff.
     * @param bool $jitter Toggles random jitter to prevent stampeding herd issues.
     * @param array<string> $retryableErrors Additional error message substrings to be considered retryable.
     * @param array<int> $retryableStatusCodes Additional HTTP status codes to be considered retryable.
     * @param callable|null $onReconnect Signature: function(int $attempt, float $delay, string|Exception $error): void
     * @param callable|null $shouldReconnect Signature: function(Exception $error): bool
     *
     * @throws TypeError if callbacks are provided but not callable.
     */
    public function __construct(
        public readonly bool $enabled = true,
        public readonly int $maxAttempts = 10,
        public readonly float $initialDelay = 1.0,
        public readonly float $maxDelay = 30.0,
        public readonly float $backoffMultiplier = 2.0,
        public readonly bool $jitter = true,
        array $retryableErrors = [],
        array $retryableStatusCodes = [],
        mixed $onReconnect = null,
        mixed $shouldReconnect = null,
    ) {
        $this->onReconnect = $onReconnect;
        $this->shouldReconnect = $shouldReconnect;

        $this->retryableErrors = \array_values(\array_unique([
            ...self::DEFAULT_RETRYABLE_ERRORS,
            ...$retryableErrors,
        ]));

        $this->retryableStatusCodes = \array_values(\array_unique([
            ...self::DEFAULT_STATUS_CODES,
            ...$retryableStatusCodes,
        ]));

        if ($this->onReconnect !== null && ! \is_callable($this->onReconnect)) {
            throw new TypeError(\sprintf(
                '%s::__construct(): Argument #9 ($onReconnect) must be of type ?callable, %s given',
                self::class,
                \get_debug_type($this->onReconnect)
            ));
        }

        if ($this->shouldReconnect !== null && ! \is_callable($this->shouldReconnect)) {
            throw new TypeError(\sprintf(
                '%s::__construct(): Argument #10 ($shouldReconnect) must be of type ?callable, %s given',
                self::class,
                \get_debug_type($this->shouldReconnect)
            ));
        }
    }

    /**
     * Calculates the reconnection delay with exponential backoff and optional jitter.
     */
    public function calculateDelay(int $attempt): float
    {
        $delay = \min(
            $this->initialDelay * \pow($this->backoffMultiplier, $attempt - 1),
            $this->maxDelay
        );

        if ($this->jitter) {
            $delay *= 1.0 - \mt_rand() / \mt_getrandmax() * 0.5;
        }

        return $delay;
    }

    /**
     * Determines if a status code should trigger a retry.
     */
    public function isRetryableStatus(int $statusCode): bool
    {
        return \in_array($statusCode, $this->retryableStatusCodes, true);
    }

    /**
     * Determines if an error is retryable based on configuration.
     */
    public function isRetryableError(Exception $error): bool
    {
        if (\is_callable($this->shouldReconnect)) {
            return (bool) \call_user_func($this->shouldReconnect, $error);
        }

        if (method_exists($error, 'getStatusCode')) {
            $code = $error->getStatusCode();
            if (\is_int($code) && $this->isRetryableStatus($code)) {
                return true;
            }
        }

        $message = $error->getMessage();
        foreach ($this->retryableErrors as $retryableError) {
            if (\str_contains($message, $retryableError)) {
                return true;
            }
        }

        return false;
    }
}