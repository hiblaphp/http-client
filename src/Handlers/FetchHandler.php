<?php

namespace Hibla\HttpClient\Handlers;

use Hibla\HttpClient\Response;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\HttpClient\StreamingResponse;
use Hibla\HttpClient\Traits\FetchOptionTrait;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Handler for fetch-style HTTP requests with advanced options support.
 *
 * This class provides a flexible, fetch-like interface for making HTTP requests
 * with support for streaming, downloads, retry logic, and caching.
 */
class FetchHandler
{
    use FetchOptionTrait;

    private StreamingHandler $streamingHandler;
    private SSEHandler $sseHandler;
    private RequestExecutorHandler $requestExecutor;
    private RetryHandler $retryHandler;
    private CacheHandler $cacheHandler;

    public function __construct(
        ?StreamingHandler $streamingHandler = null,
        ?SSEHandler $sseHandler = null,
        ?RequestExecutorHandler $requestExecutor = null,
        ?RetryHandler $retryHandler = null,
        ?CacheHandler $cacheHandler = null
    ) {
        $this->streamingHandler = $streamingHandler ?? new StreamingHandler();
        $this->sseHandler = $sseHandler ?? new SSEHandler();
        $this->requestExecutor = $requestExecutor ?? new RequestExecutorHandler();
        $this->retryHandler = $retryHandler ?? new RetryHandler();
        $this->cacheHandler = $cacheHandler ?? new CacheHandler($this->requestExecutor, $this->retryHandler);
    }

    /**
     * A flexible, fetch-like method for making HTTP requests with streaming support.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  An associative array of request options.
     * @return PromiseInterface<Response>|CancellablePromiseInterface<StreamingResponse>|CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>, protocol_version: string|null, size: int|false}>|CancellablePromiseInterface<SSEResponse> A promise that resolves with a Response, StreamingResponse, download metadata, or SSEResponse.
     */
    public function fetch(string $url, array $options = []): PromiseInterface|CancellablePromiseInterface
    {
        if ($this->isDownloadRequested($options)) {
            return $this->fetchDownload($url, $options);
        }

        if ($this->isSSERequested($options)) {
            $sseConfig = $this->extractSSEConfig($options);

            $onEvent = $sseConfig['onEvent'];
            $onError = $sseConfig['onError'];
            $reconnectConfig = $sseConfig['reconnectConfig'];

            return $this->fetchSSE(
                $url,
                $options,
                $onEvent,
                $onError,
                $reconnectConfig
            );
        }

        $isStreaming = $this->isStreamingRequested($options);
        $onChunk = $this->extractOnChunkCallback($options);

        if ($isStreaming) {
            return $this->fetchStream($url, $options, $onChunk);
        }

        $retryConfig = $this->extractRetryConfig($options);
        $cacheConfig = $this->extractCacheConfig($options);

        $curlOptions = $this->normalizeFetchOptions($url, $options);

        if ($cacheConfig !== null) {
            return $this->cacheHandler->execute($url, $curlOptions, $cacheConfig, $retryConfig);
        }

        if ($retryConfig !== null) {
            return $this->retryHandler->execute($url, $curlOptions, $retryConfig);
        }

        return $this->requestExecutor->execute($url, $curlOptions);
    }

    /**
     * Handles download requests through fetch.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  Request options.
     * @return CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>, protocol_version: string|null, size: int|false}> A promise that resolves with download metadata.
     */
    private function fetchDownload(string $url, array $options): CancellablePromiseInterface
    {
        $destination = $options['download'] ?? $options['save_to'] ?? null;

        if (! is_string($destination)) {
            throw new \InvalidArgumentException('Download destination must be a string path');
        }

        // Normalize options to cURL format
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        return $this->streamingHandler->downloadFile($url, $destination, $curlOptions);
    }

    /**
     * Checks if download is requested in options.
     *
     * @param  array<int|string, mixed>  $options  The options array.
     * @return bool True if download is requested.
     */
    private function isDownloadRequested(array $options): bool
    {
        return isset($options['download']) || isset($options['save_to']);
    }

    /**
     * Handles streaming requests through fetch.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  Request options.
     * @param  callable|null  $onChunk  Optional chunk callback.
     * @return CancellablePromiseInterface<StreamingResponse> A promise that resolves with a StreamingResponse.
     */
    private function fetchStream(string $url, array $options, ?callable $onChunk = null): CancellablePromiseInterface
    {
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        // Remove headers that might interfere with streaming
        unset($curlOptions[CURLOPT_HEADER]);

        return $this->streamingHandler->streamRequest($url, $curlOptions, $onChunk);
    }

    /**
     * Checks if streaming is requested in options.
     *
     * @param  array<int|string, mixed>  $options  The options array.
     * @return bool True if streaming is requested.
     */
    private function isStreamingRequested(array $options): bool
    {
        return isset($options['stream']) && $options['stream'] === true;
    }

    /**
     * Extracts the onChunk callback from options.
     *
     * @param  array<int|string, mixed>  $options  The options array.
     * @return callable|null The chunk callback if provided.
     */
    private function extractOnChunkCallback(array $options): ?callable
    {
        if (isset($options['on_chunk']) && is_callable($options['on_chunk'])) {
            return $options['on_chunk'];
        }

        // Also support 'onChunk' for consistency with Request builder
        if (isset($options['onChunk']) && is_callable($options['onChunk'])) {
            return $options['onChunk'];
        }

        return null;
    }

    /**
     * Checks if SSE is requested in options.
     *
     * @param  array<int|string, mixed>  $options  The options array
     * @return bool True if SSE is requested
     */
    private function isSSERequested(array $options): bool
    {
        return isset($options['sse']) && $options['sse'] === true;
    }

    /**
     * Extract SSE callbacks from options.
     *
     * @param  array<int|string, mixed>  $options  The options array
     * @return array{onEvent: callable|null, onError: callable|null}
     */
    private function extractSSECallbacks(array $options): array
    {
        $onEvent = null;
        $onError = null;

        if (isset($options['on_event']) && is_callable($options['on_event'])) {
            $onEvent = $options['on_event'];
        } elseif (isset($options['onEvent']) && is_callable($options['onEvent'])) {
            $onEvent = $options['onEvent'];
        }

        if (isset($options['on_error']) && is_callable($options['on_error'])) {
            $onError = $options['on_error'];
        } elseif (isset($options['onError']) && is_callable($options['onError'])) {
            $onError = $options['onError'];
        }

        return ['onEvent' => $onEvent, 'onError' => $onError];
    }

    /**
     * Handles SSE requests through fetch.
     * @param array<int|string, mixed> $options
     * @return CancellablePromiseInterface<SSEResponse>
     */
    private function fetchSSE(
        string $url,
        array $options,
        ?callable $onEvent = null,
        ?callable $onError = null,
        ?SSEReconnectConfig $reconnectConfig = null
    ): CancellablePromiseInterface {
        $curlOptions = $this->normalizeFetchOptions($url, $options);

        /** @var array<int, mixed> $curlOnlyOptions */
        $curlOnlyOptions = array_filter($curlOptions, 'is_int', ARRAY_FILTER_USE_KEY);

        return $this->sseHandler->connect($url, $curlOnlyOptions, $onEvent, $onError, $reconnectConfig);
    }

    /**
     * Extract SSE configuration from options.
     * @param array<int|string, mixed> $options
     * @return array{onEvent: callable|null, onError: callable|null, reconnectConfig: SSEReconnectConfig|null}
     */
    private function extractSSEConfig(array $options): array
    {
        $callbacks = $this->extractSSECallbacks($options);
        $reconnectConfig = $this->extractSSEReconnectConfig($options);

        return [
            'onEvent' => $callbacks['onEvent'],
            'onError' => $callbacks['onError'],
            'reconnectConfig' => $reconnectConfig,
        ];
    }

    /**
     * Extract SSE reconnection config from options.
     * @param array<int|string, mixed> $options
     */
    private function extractSSEReconnectConfig(array $options): ?SSEReconnectConfig
    {
        if (! isset($options['reconnect'])) {
            return null;
        }

        $reconnect = $options['reconnect'];

        if ($reconnect === true) {
            return new SSEReconnectConfig();
        }

        if ($reconnect instanceof SSEReconnectConfig) {
            return $reconnect;
        }

        if (is_array($reconnect)) {
            $enabled = isset($reconnect['enabled']) ? (bool)$reconnect['enabled'] : true;
            $maxAttempts = (isset($reconnect['max_attempts']) && is_numeric($reconnect['max_attempts'])) ? (int)$reconnect['max_attempts'] : 10;
            $initialDelay = (isset($reconnect['initial_delay']) && is_numeric($reconnect['initial_delay'])) ? (float)$reconnect['initial_delay'] : 1.0;
            $maxDelay = (isset($reconnect['max_delay']) && is_numeric($reconnect['max_delay'])) ? (float)$reconnect['max_delay'] : 30.0;
            $backoffMultiplier = (isset($reconnect['backoff_multiplier']) && is_numeric($reconnect['backoff_multiplier'])) ? (float)$reconnect['backoff_multiplier'] : 2.0;
            $jitter = isset($reconnect['jitter']) ? (bool)$reconnect['jitter'] : true;
            $onReconnect = (isset($reconnect['on_reconnect']) && is_callable($reconnect['on_reconnect'])) ? $reconnect['on_reconnect'] : null;
            $shouldReconnect = (isset($reconnect['should_reconnect']) && is_callable($reconnect['should_reconnect'])) ? $reconnect['should_reconnect'] : null;

            $defaultErrors = [
                'Connection refused',
                'Connection reset',
                'Connection timed out',
                'Could not resolve host',
                'Resolving timed out',
                'SSL connection timeout',
                'Operation timed out',
                'Network is unreachable',
            ];
            $retryableErrors = (isset($reconnect['retryable_errors']) && is_array($reconnect['retryable_errors']))
                ? $reconnect['retryable_errors']
                : $defaultErrors;

            return new SSEReconnectConfig(
                enabled: $enabled,
                maxAttempts: $maxAttempts,
                initialDelay: $initialDelay,
                maxDelay: $maxDelay,
                backoffMultiplier: $backoffMultiplier,
                jitter: $jitter,
                retryableErrors: array_values(array_filter($retryableErrors, 'is_string')),
                onReconnect: $onReconnect,
                shouldReconnect: $shouldReconnect
            );
        }

        return null;
    }
}
