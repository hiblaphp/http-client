<?php

namespace Hibla\Http\SSE;

use Exception;
use Hibla\EventLoop\EventLoop;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;

/**
 * Manages the state of an SSE connection including reconnection attempts.
 */
class SSEConnectionState
{
    private int $attemptCount = 0;
    private ?string $lastEventId = null;
    private ?int $retryInterval = null;
    private ?CancellablePromiseInterface $currentConnection = null;
    private ?Exception $lastError = null;
    private bool $cancelled = false;
    private ?string $reconnectTimerId = null;

    public function __construct(
        private readonly string $url,
        private readonly array $options,
        private readonly SSEReconnectConfig $config
    ) {}

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getConfig(): SSEReconnectConfig
    {
        return $this->config;
    }

    public function incrementAttempt(): void
    {
        $this->attemptCount++;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function setLastEventId(?string $eventId): void
    {
        $this->lastEventId = $eventId;
    }

    public function getLastEventId(): ?string
    {
        return $this->lastEventId;
    }

    public function setRetryInterval(?int $interval): void
    {
        $this->retryInterval = $interval;
    }

    public function getRetryInterval(): ?int
    {
        return $this->retryInterval;
    }

    public function setReconnectTimerId(?string $timerId): void
    {
        $this->reconnectTimerId = $timerId;
    }

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

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function setCurrentConnection(CancellablePromiseInterface $connection): void
    {
        $this->currentConnection = $connection;
    }

    public function onConnected(): void
    {
        $this->attemptCount = 0;
        $this->lastError = null;
    }

    public function onConnectionFailed(Exception $error): void
    {
        $this->lastError = $error;
    }

    public function shouldReconnect(?Exception $error = null): bool
    {
        // This is now the primary guard against creating "zombie" connections.
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

    public function getReconnectDelay(): float
    {
        if ($this->retryInterval !== null) {
            return $this->retryInterval / 1000.0;
        }

        return $this->config->calculateDelay($this->attemptCount);
    }
}
