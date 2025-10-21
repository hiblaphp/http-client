<?php

namespace Hibla\HttpClient\Exceptions;

use Hibla\HttpClient\Interfaces\NetworkExceptionInterface;

/**
 * Thrown when a request times out.
 * This includes connection timeouts and operation timeouts.
 */
class TimeoutException extends NetworkException implements NetworkExceptionInterface
{
    private ?float $timeout = null;
    private ?string $timeoutType = null; // 'connection' or 'operation'

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $url = null,
        ?string $errorType = null,
        ?float $timeout = null,
        ?string $timeoutType = null
    ) {
        parent::__construct($message, $code, $previous, $url, $errorType);
        $this->timeout = $timeout;
        $this->timeoutType = $timeoutType;
    }

    /**
     * Get the timeout value in seconds.
     */
    public function getTimeout(): ?float
    {
        return $this->timeout;
    }

    /**
     * Set the timeout value in seconds.
     */
    public function setTimeout(float $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * Get the type of timeout ('connection' or 'operation').
     */
    public function getTimeoutType(): ?string
    {
        return $this->timeoutType;
    }

    /**
     * Set the type of timeout.
     */
    public function setTimeoutType(string $timeoutType): void
    {
        $this->timeoutType = $timeoutType;
    }

    /**
     * Check if this was a connection timeout.
     */
    public function isConnectionTimeout(): bool
    {
        return $this->timeoutType === 'connection';
    }

    /**
     * Check if this was an operation timeout.
     */
    public function isOperationTimeout(): bool
    {
        return $this->timeoutType === 'operation';
    }
}
