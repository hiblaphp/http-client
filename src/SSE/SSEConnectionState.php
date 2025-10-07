<?php

namespace Hibla\HttpClient\SSE;

use Exception;
use Hibla\EventLoop\EventLoop;
use Hibla\HttpClient\StreamingResponse;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;

/**
 * Manages the state of an SSE connection including reconnection attempts.
 *
 * @template TResponse of StreamingResponse
 */
class SSEConnectionState
{
    private int $attemptCount = 0;
    private ?string $lastEventId = null;
    private ?int $retryInterval = null;

    /**
     * @var CancellablePromiseInterface<TResponse>|null
     */
    private ?CancellablePromiseInterface $currentConnection = null;
    private ?Exception $lastError = null;
    private bool $cancelled = false;
    private ?string $reconnectTimerId = null;

    /**
     * Constructs a new SSEConnectionState instance.
     *
     * @param string $url The target URL for the SSE connection.
     * @param array<int|string, mixed> $options cURL options for the request.
     * @param SSEReconnectConfig $config Reconnection configuration.
     */
    public function __construct(
        private readonly string $url,
        private readonly array $options,
        private readonly SSEReconnectConfig $config
    ) {
    }

    /**
     * Gets the connection URL.
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Gets the cURL request options.
     *
     * @return array<int|string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Gets the reconnection configuration.
     */
    public function getConfig(): SSEReconnectConfig
    {
        return $this->config;
    }

    /**
     * Increments the reconnection attempt counter.
     */
    public function incrementAttempt(): void
    {
        $this->attemptCount++;
    }

    /**
     * Gets the current number of reconnection attempts.
     */
    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    /**
     * Sets the ID of the last received event.
     */
    public function setLastEventId(?string $eventId): void
    {
        $this->lastEventId = $eventId;
    }

    /**
     * Gets the ID of the last received event.
     */
    public function getLastEventId(): ?string
    {
        return $this->lastEventId;
    }

    /**
     * Sets the server-advised retry interval in milliseconds.
     */
    public function setRetryInterval(?int $interval): void
    {
        $this->retryInterval = $interval;
    }

    /**
     * Gets the server-advised retry interval.
     */
    public function getRetryInterval(): ?int
    {
        return $this->retryInterval;
    }

    /**
     * Stores the ID of the scheduled reconnect timer.
     */
    public function setReconnectTimerId(?string $timerId): void
    {
        $this->reconnectTimerId = $timerId;
    }

    /**
     * Cancels the connection and any pending reconnection attempts.
     */
    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }
        $this->cancelled = true;

        if ($this->currentConnection !== null && $this->currentConnection->isPending()) {
            $this->currentConnection->cancel();
        }

        if ($this->reconnectTimerId !== null) {
            EventLoop::getInstance()->cancelTimer($this->reconnectTimerId);
            $this->reconnectTimerId = null;
        }
    }

    /**
     * Checks if the connection has been cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Sets the promise for the current active connection.
     *
     * @param CancellablePromiseInterface<TResponse> $connection
     */
    public function setCurrentConnection(CancellablePromiseInterface $connection): void
    {
        $this->currentConnection = $connection;
    }

    /**
     * Resets the connection state upon a successful connection.
     */
    public function onConnected(): void
    {
        $this->attemptCount = 0;
        $this->lastError = null;
    }

    /**
     * Records the error when a connection attempt fails.
     */
    public function onConnectionFailed(Exception $error): void
    {
        $this->lastError = $error;
    }

    /**
     * Determines if a reconnection attempt should be made.
     */
    public function shouldReconnect(?Exception $error = null): bool
    {
        if ($this->cancelled) {
            return false;
        }

        if (! $this->config->enabled) {
            return false;
        }

        if ($this->attemptCount >= $this->config->maxAttempts) {
            return false;
        }

        $errorToCheck = $error ?? $this->lastError;
        if ($errorToCheck !== null) {
            return $this->config->isRetryableError($errorToCheck);
        }

        return true;
    }

    /**
     * Calculates the delay for the next reconnection attempt in seconds.
     */
    public function getReconnectDelay(): float
    {
        if ($this->retryInterval !== null) {
            return $this->retryInterval / 1000.0;
        }

        return $this->config->calculateDelay($this->attemptCount);
    }
}
