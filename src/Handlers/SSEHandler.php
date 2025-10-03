<?php

namespace Hibla\Http\Handlers;

use Hibla\EventLoop\EventLoop;
use Hibla\Http\Exceptions\HttpStreamException;
use Hibla\Http\Exceptions\NetworkException;
use Hibla\Http\Exceptions\RequestException;
use Hibla\Http\SSE\SSEConnectionState;
use Hibla\Http\SSE\SSEEvent;
use Hibla\Http\SSE\SSEReconnectConfig;
use Hibla\Http\SSE\SSEResponse;
use Hibla\Http\Stream;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;

/**
 * Dedicated handler for Server-Sent Events (SSE) connections with reconnection support.
 */
class SSEHandler
{
    private StreamingHandler $streamingHandler;

    public function __construct(?StreamingHandler $streamingHandler = null)
    {
        $this->streamingHandler = $streamingHandler ?? new StreamingHandler;
    }

    /**
     * Creates an SSE connection with optional reconnection logic.
     *
     * @param  string  $url  The SSE endpoint URL
     * @param  array<int, mixed>  $options  cURL options
     * @param  callable(SSEEvent): void|null  $onEvent  Optional callback for each SSE event
     * @param  callable(string): void|null  $onError  Optional callback for connection errors
     * @param  SSEReconnectConfig|null  $reconnectConfig  Optional reconnection configuration
     * @return CancellablePromiseInterface<SSEResponse>
     */
    public function connect(
        string $url,
        array $options = [],
        ?callable $onEvent = null,
        ?callable $onError = null,
        ?SSEReconnectConfig $reconnectConfig = null
    ): CancellablePromiseInterface {
        if ($reconnectConfig !== null && $reconnectConfig->enabled) {
            return $this->connectWithReconnection($url, $options, $onEvent, $onError, $reconnectConfig);
        }

        return $this->createSSEConnection($url, $options, $onEvent, $onError);
    }

    /**
     * Creates an SSE connection with automatic reconnection logic.
     */
    private function connectWithReconnection(
        string $url,
        array $options,
        ?callable $onEvent,
        ?callable $onError,
        SSEReconnectConfig $reconnectConfig
    ): CancellablePromiseInterface {
        /** @var CancellablePromise<SSEResponse> $mainPromise */
        $mainPromise = new CancellablePromise;
        $connectionState = new SSEConnectionState($url, $options, $reconnectConfig);

        $wrappedOnEvent = $this->wrapEventCallback($onEvent, $connectionState);
        $wrappedOnError = $this->wrapErrorCallback($onError, $connectionState);

        // Start the first connection attempt
        $this->attemptConnection($connectionState, $wrappedOnEvent, $wrappedOnError, $mainPromise);

        // The main promise's cancellation now controls the entire state machine.
        $mainPromise->setCancelHandler(function () use ($connectionState) {
            $connectionState->cancel();
        });

        return $mainPromise;
    }

    /**
     * Attempts to establish an SSE connection.
     */
    private function attemptConnection(
        SSEConnectionState $connectionState,
        ?callable $onEvent,
        ?callable $onError,
        CancellablePromise $mainPromise
    ): void {
        if ($connectionState->isCancelled()) {
            if (! $mainPromise->isSettled()) {
                $exception = new RequestException(
                    'SSE connection cancelled before attempt.',
                    0,
                    null,
                    $connectionState->getUrl()
                );
                $mainPromise->reject($exception);
            }

            return;
        }

        $connectionState->incrementAttempt();

        $options = $connectionState->getOptions();
        if ($connectionState->getLastEventId() !== null) {
            $headers = $options[CURLOPT_HTTPHEADER] ?? [];
            // Remove previous Last-Event-ID header if it exists to avoid duplicates
            $headers = array_filter($headers, fn ($h) => ! str_starts_with(strtolower($h), 'last-event-id:'));
            $headers[] = 'Last-Event-ID: '.$connectionState->getLastEventId();
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        $connectionPromise = $this->createSSEConnection($connectionState->getUrl(), $options, $onEvent, $onError);
        $connectionState->setCurrentConnection($connectionPromise);

        $connectionPromise->then(
            function (SSEResponse $response) use ($mainPromise, $connectionState) {
                if ($connectionState->isCancelled()) {
                    return;
                }

                if (! $mainPromise->isSettled()) {
                    $mainPromise->resolve($response);
                }
                $connectionState->onConnected();
            },
            function (\Throwable $error) use ($mainPromise, $connectionState, $onEvent, $onError) {
                // When a connection fails, check the master cancellation flag first.
                if ($connectionState->isCancelled()) {
                    if (! $mainPromise->isSettled()) {
                        $exception = new RequestException(
                            'SSE connection cancelled during failure handling.',
                            0,
                            $error,
                            $connectionState->getUrl()
                        );
                        $mainPromise->reject($exception);
                    }

                    return;
                }

                if (! $connectionState->shouldReconnect($error)) {
                    if (! $mainPromise->isSettled()) {
                        $mainPromise->reject($error);
                    }

                    return;
                }

                $delay = $connectionState->getReconnectDelay();
                $connectionState->getConfig()->onReconnect?->call($this, $connectionState->getAttemptCount(), $delay, $error);

                // When we schedule the timer, we get its ID and store it in the state object.
                $timerId = EventLoop::getInstance()->addTimer($delay, function () use ($connectionState, $onEvent, $onError, $mainPromise) {
                    $connectionState->setReconnectTimerId(null); // Timer is firing, so clear the ID.
                    $this->attemptConnection($connectionState, $onEvent, $onError, $mainPromise);
                });
                $connectionState->setReconnectTimerId($timerId);
            }
        );
    }

    /**
     * Creates a basic SSE connection without reconnection.
     */
    private function createSSEConnection(
        string $url,
        array $options,
        ?callable $onEvent,
        ?callable $onError
    ): CancellablePromiseInterface {
        /** @var CancellablePromise<SSEResponse> $promise */
        $promise = new CancellablePromise;
        $sseResponse = null;
        $headersProcessed = false;

        $curlOnlyOptions = array_filter($options, 'is_int', ARRAY_FILTER_USE_KEY);
        $sseOptions = array_replace($curlOnlyOptions, [
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array_merge(
                $curlOnlyOptions[CURLOPT_HTTPHEADER] ?? [],
                ['Accept: text/event-stream', 'Cache-Control: no-cache', 'Connection: keep-alive']
            ),
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use ($onEvent, &$sseResponse) {
                if ($sseResponse !== null && $onEvent !== null) {
                    try {
                        $events = $sseResponse->parseEvents($data);
                        foreach ($events as $event) {
                            $onEvent($event);
                        }
                    } catch (\Throwable $e) {
                        error_log('SSE event parsing error: '.$e->getMessage());
                    }
                }

                return strlen($data);
            },
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use ($url, $promise, &$sseResponse, &$headersProcessed) {
                if ($promise->isSettled()) {
                    return strlen($header);
                }

                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if (! $headersProcessed && $httpCode > 0) {
                    if ($httpCode >= 200 && $httpCode < 300) {
                        $sseResponse = new SSEResponse(new Stream(fopen('php://temp', 'r+')), $httpCode, []);
                        $promise->resolve($sseResponse);
                    } else {
                        $exception = new HttpStreamException(
                            "SSE connection failed with status: {$httpCode}",
                            0,
                            null,
                            $url
                        );
                        $exception->setStreamState('invalid_status_code');
                        $promise->reject($exception);
                    }
                    $headersProcessed = true;
                }

                return strlen($header);
            },
        ]);

        $requestId = EventLoop::getInstance()->addHttpRequest(
            $url,
            $sseOptions,
            function (?string $error) use ($url, $promise, $onError) {
                if ($promise->isSettled()) {
                    if ($onError !== null && $error !== null) {
                        $onError($error);
                    }

                    return;
                }
                
                $exception = new NetworkException(
                    "SSE connection failed: {$error}",
                    0,
                    null,
                    $url,
                    $error
                );
                $promise->reject($exception);
            }
        );

        $promise->setCancelHandler(fn () => EventLoop::getInstance()->cancelHttpRequest($requestId));

        return $promise;
    }

    /**
     * Wraps the event callback to handle last event ID tracking.
     */
    private function wrapEventCallback(?callable $onEvent, SSEConnectionState $state): ?callable
    {
        if ($onEvent === null) {
            return null;
        }

        return function (SSEEvent $event) use ($onEvent, $state) {
            if ($event->id !== null) {
                $state->setLastEventId($event->id);
            }
            if ($event->retry !== null) {
                $state->setRetryInterval($event->retry);
            }
            $onEvent($event);
        };
    }

    /**
     * Wraps the error callback to handle reconnection logic.
     */
    private function wrapErrorCallback(?callable $onError, SSEConnectionState $state): ?callable
    {
        return function (string $error) use ($onError, $state) {
            if ($onError !== null) {
                $onError($error);
            }
            
            $exception = new NetworkException(
                $error,
                0,
                null,
                $state->getUrl(),
                $error
            );
            
            $state->onConnectionFailed($exception);
        };
    }
}