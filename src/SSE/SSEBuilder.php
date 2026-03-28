<?php

declare(strict_types=1);

namespace Hibla\HttpClient\SSE;

use Hibla\HttpClient\Handlers\HttpHandler;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Immutable fluent builder for SSE connections.
 *
 * Every configuration method returns a new instance, making it safe
 * to derive multiple connections from a shared base configuration:
 *
 *   $base = Http::request()
 *       ->withToken($token)
 *       ->sse('https://api.example.com/stream')
 *       ->dataFormat('json')
 *       ->reconnect(maxAttempts: 5);
 *
 *   // Safe — each derives from $base without mutating it
 *   $streamA = $base->onEvent(fn($data) => handleA($data))->connect();
 *   $streamB = $base->onEvent(fn($data) => handleB($data))->connect();
 */
class SSEBuilder
{
    /**
     * @param callable(mixed): mixed $mapper
     */
    private mixed $mapper = null;

    /**
     * @param callable(mixed): mixed $mapper
     */
    private mixed $onEvent = null;

    /**
     * @param callable(\Throwable): mixed $mapper
     */
    private mixed $onError = null;

    private ?SSEReconnectConfig $reconnectConfig = null;

    private ?SSEDataFormat $dataFormat = null;

    /**
     * @param array<int|string, mixed> $curlOptions Pre-built transport options from the Request.
     */
    public function __construct(
        private readonly string $url,
        private readonly HttpHandler $handler,
        private readonly array $curlOptions,
    ) {
    }

    /**
     * Register a callback to receive each SSE event.
     *
     * The value passed to the callback depends on the configured dataFormat:
     *   - 'event' (default): SSEEvent object
     *   - 'json':  decoded array/scalar, or raw string if not valid JSON
     *   - 'array': event as array with data key auto-decoded
     *   - 'raw':   raw data string
     *
     * If map() is also configured, the callback receives the mapped value.
     *
     * @param callable(mixed): void $callback
     */
    public function onEvent(callable $callback): self
    {
        $new = clone $this;
        $new->onEvent = $callback;

        return $new;
    }

    /**
     * Register a callback to receive connection errors.
     *
     * Always receives a \Throwable for consistency with the rest of the library.
     *
     * @param callable(\Throwable): void $callback
     */
    public function onError(callable $callback): self
    {
        $new = clone $this;
        $new->onError = $callback;

        return $new;
    }

    /**
     * Configure the format of data passed to the onEvent callback.
     *
     * @param SSEDataFormat $format
     *   - SSEDataFormat::Event  — full SSEEvent object (default)
     *   - SSEDataFormat::Json   — event data decoded as JSON, falls back to raw string
     *   - SSEDataFormat::Array_ — event->toArray() with data key auto-decoded from JSON
     *   - SSEDataFormat::Raw    — raw event data string
     */
    public function dataFormat(SSEDataFormat $format): self
    {
        $new = clone $this;
        $new->dataFormat = $format;

        return $new;
    }

    /**
     * Apply a transformation to each event value after dataFormat is applied
     * but before the onEvent callback receives it.
     *
     * @param callable(mixed): mixed $mapper
     */
    public function map(callable $mapper): self
    {
        $new = clone $this;
        $new->mapper = $mapper;

        return $new;
    }

    /**
     * Enable automatic reconnection with exponential backoff.
     *
     * @param int   $maxAttempts      Maximum reconnection attempts before giving up.
     * @param float $initialDelay     Seconds before the first retry.
     * @param float $maxDelay         Upper bound on delay between retries.
     * @param float $backoffMultiplier Factor applied to delay on each attempt.
     * @param bool  $jitter           Adds randomness to prevent thundering herd.
     */
    public function reconnect(
        int $maxAttempts = 10,
        float $initialDelay = 1.0,
        float $maxDelay = 30.0,
        float $backoffMultiplier = 2.0,
        bool $jitter = true,
    ): self {
        $new = clone $this;
        $new->reconnectConfig = new SSEReconnectConfig(
            enabled: true,
            maxAttempts: $maxAttempts,
            initialDelay: $initialDelay,
            maxDelay: $maxDelay,
            backoffMultiplier: $backoffMultiplier,
            jitter: $jitter,
        );

        return $new;
    }

    /**
     * Provide a fully custom reconnection configuration.
     */
    public function reconnectWith(SSEReconnectConfig $config): self
    {
        $new = clone $this;
        $new->reconnectConfig = $config;

        return $new;
    }

    /**
     * Explicitly disable reconnection (overrides any previously set config).
     */
    public function noReconnect(): self
    {
        $new = clone $this;
        $new->reconnectConfig = new SSEReconnectConfig(enabled: false);

        return $new;
    }

    /**
     * Open the SSE connection with the current configuration.
     *
     * @return PromiseInterface<SSEResponse>
     */
    public function connect(): PromiseInterface
    {
        $control = new SSEControl();

        $promise = $this->handler->sse(
            $this->url,
            $this->curlOptions,
            $this->buildEventCallback($control),
            $this->buildErrorCallback(),
            $this->reconnectConfig,
        );

        $control->setPromise($promise);

        return $promise;
    }

    private function buildEventCallback(SSEControl $control): ?callable
    {
        if ($this->onEvent === null) {
            return null;
        }

        $onEvent = $this->onEvent;
        $dataFormat = $this->dataFormat;
        $mapper = $this->mapper;

        return function (SSEEvent $event) use ($onEvent, $dataFormat, $mapper, $control): void {
            if ($control->isCancelled()) {
                return;
            }

            $data = match ($dataFormat) {
                SSEDataFormat::Json => $this->parseAsJson($event),
                SSEDataFormat::Array => $this->toArrayWithParsedData($event),
                SSEDataFormat::Raw => $event->data,
                SSEDataFormat::Event, null => $event,
            };

            if ($mapper !== null) {
                $data = $mapper($data);
            }

            $onEvent($data, $control);
        };
    }

    private function buildErrorCallback(): ?callable
    {
        if ($this->onError === null) {
            return null;
        }

        $onError = $this->onError;

        return function (string $error) use ($onError): void {
            $onError(new \RuntimeException($error));
        };
    }

    private function parseAsJson(SSEEvent $event): mixed
    {
        if ($event->data === null) {
            return null;
        }

        $parsed = json_decode($event->data, true);

        return json_last_error() === JSON_ERROR_NONE ? $parsed : $event->data;
    }

    /**
     *  @return array<string, mixed>
     */
    private function toArrayWithParsedData(SSEEvent $event): array
    {
        $array = $event->toArray();

        if ($array['data'] !== null && \is_string($array['data'])) {
            $parsed = json_decode($array['data'], true);
            if (json_last_error() === JSON_ERROR_NONE && \is_array($parsed)) {
                $array['data'] = $parsed;
            }
        }

        return $array;
    }
}
