<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\SSE;

use Hibla\HttpClient\SSE\SSEControl;
use Hibla\HttpClient\SSE\SSEDataFormat;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Fluent builder interface for configuring and opening SSE connections.
 *
 * Every configuration method must return a new instance so that a
 * shared base configuration can safely derive multiple independent
 * connections without side effects.
 *
 *   $base = Http::request()
 *       ->withToken($token)
 *       ->sse('https://api.example.com/stream')
 *       ->dataFormat(SSEDataFormat::Json)
 *       ->reconnect(maxAttempts: 5);
 *
 *   // Safe — each derives from $base without mutating it
 *   $streamA = $base->onEvent(fn($data) => handleA($data))->connect();
 *   $streamB = $base->onEvent(fn($data) => handleB($data))->connect();
 */
interface SSEBuilderInterface
{
    /**
     * Register a callback to receive each SSE event.
     *
     * The value passed to the callback depends on the configured dataFormat:
     *   - SSEDataFormat::Event  (default): SSEEvent object
     *   - SSEDataFormat::Json:  decoded array/scalar, or raw string if not valid JSON
     *   - SSEDataFormat::Array: event as array with data key auto-decoded from JSON
     *   - SSEDataFormat::Raw:   raw data string
     *
     * If map() is also configured, the callback receives the mapped value.
     *
     * @param callable(mixed, SSEControl): void $callback
     */
    public function onEvent(callable $callback): static;

    /**
     * Register a callback to receive connection errors.
     *
     * Always receives a \Throwable for consistency with the rest of the library.
     *
     * @param callable(\Throwable): void $callback
     */
    public function onError(callable $callback): static;

    /**
     * Configure the format of data passed to the onEvent callback.
     */
    public function dataFormat(SSEDataFormat $format): static;

    /**
     * Apply a transformation to each event value after dataFormat is applied
     * but before the onEvent callback receives it.
     *
     * @param callable(mixed): mixed $mapper
     */
    public function map(callable $mapper): static;

    /**
     * Enable automatic reconnection with exponential backoff.
     *
     * @param int   $maxAttempts       Maximum reconnection attempts before giving up.
     * @param float $initialDelay      Seconds before the first retry.
     * @param float $maxDelay          Upper bound on delay between retries.
     * @param float $backoffMultiplier Factor applied to delay on each attempt.
     * @param bool  $jitter            Adds randomness to prevent thundering herd.
     */
    public function reconnect(
        int $maxAttempts = 10,
        float $initialDelay = 1.0,
        float $maxDelay = 30.0,
        float $backoffMultiplier = 2.0,
        bool $jitter = true,
    ): static;

    /**
     * Provide a fully custom reconnection configuration.
     */
    public function reconnectWith(SSEReconnectConfig $config): static;

    /**
     * Explicitly disable reconnection (overrides any previously set config).
     */
    public function noReconnect(): static;

    /**
     * Open the SSE connection with the current configuration.
     *
     * @return PromiseInterface<SSEResponse>
     */
    public function connect(): PromiseInterface;
}
