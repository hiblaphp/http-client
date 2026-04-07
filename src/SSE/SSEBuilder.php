<?php

declare(strict_types=1);

namespace Hibla\HttpClient\SSE;

use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Interfaces\SSE\SSEBuilderInterface;
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
 *       ->dataFormat(SSEDataFormat::DecodedJson)
 *       ->reconnect(maxAttempts: 5);
 *
 *   // Safe — each derives from $base without mutating it
 *   $streamA = $base->onEvent(fn($data) => handleA($data))->connect();
 *   $streamB = $base->onEvent(fn($data) => handleB($data))->connect();
 */
class SSEBuilder implements SSEBuilderInterface
{
    /**
     *  @var (callable(mixed): mixed)|null
     */
    private $mapper = null;

    /**
     *  @var (callable(mixed, SSEControl): void)|null
     */
    private $onEvent = null;

    /**
     *  @var (callable(\Throwable): void)|null
     */
    private $onError = null;

    private ?SSEReconnectConfig $reconnectConfig = null;

    private ?SSEDataFormat $dataFormat = null;

    /**
     * @param string $url The target SSE endpoint.
     * @param mixed $connector A closure provided by the client to execute the connection attempt.
     */
    public function __construct(
        private readonly string $url,
        private readonly mixed $connector,
    ) {
    }

    /**
     *  @inheritDoc
     */
    public function onEvent(callable $callback): static
    {
        $new = clone $this;
        $new->onEvent = $callback;

        return $new;
    }

    /**
     *  @inheritDoc
     */
    public function onError(callable $callback): static
    {
        $new = clone $this;
        $new->onError = $callback;

        return $new;
    }

    /**
     *  @inheritDoc
     */
    public function dataFormat(SSEDataFormat $format): static
    {
        $new = clone $this;
        $new->dataFormat = $format;

        return $new;
    }

    /**
     *  @inheritDoc
     */
    public function map(callable $mapper): static
    {
        $new = clone $this;
        $new->mapper = $mapper;

        return $new;
    }

    /**
     *  @inheritDoc
     */
    public function reconnect(
        int $maxAttempts = 10,
        float $initialDelay = 1.0,
        float $maxDelay = 30.0,
        float $backoffMultiplier = 2.0,
        bool $jitter = true,
    ): static {
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
     *  @inheritDoc
     */
    public function reconnectWith(SSEReconnectConfig $config): static
    {
        $new = clone $this;
        $new->reconnectConfig = $config;

        return $new;
    }

    /**
     *  @inheritDoc
     */
    public function noReconnect(): static
    {
        $new = clone $this;
        $new->reconnectConfig = new SSEReconnectConfig(enabled: false);

        return $new;
    }

    /**
     *  @inheritDoc
     */
    public function connect(): PromiseInterface
    {
        $control = new SSEControl();

        $promise = ($this->connector)(
            $this->url,
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
                SSEDataFormat::DecodedJson => $this->parseAsJson($event),
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
        $url = $this->url;

        return function (string $error) use ($onError, $url): void {
            $onError(new NetworkException(
                "SSE connection failed: {$error}",
                0,
                null,
                $url,
                $error
            ));
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
