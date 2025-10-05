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
    /**
     * Creates an SSE connection with optional reconnection logic.
     *
     * @param  string  $url  The SSE endpoint URL
     * @param  array<int|string, mixed>  $options  cURL options
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
     *
     * @param  array<int|string, mixed>  $options
     * @return CancellablePromiseInterface<SSEResponse>
     */
    private function connectWithReconnection(
        string $url,
        array $options,
        ?callable $onEvent,
        ?callable $onError,
        SSEReconnectConfig $reconnectConfig
    ): CancellablePromiseInterface {
        /** @var CancellablePromise<SSEResponse> $mainPromise */
        $mainPromise = new CancellablePromise();

        /** @var SSEConnectionState<SSEResponse> $connectionState */
        $connectionState = new SSEConnectionState($url, $options, $reconnectConfig);

        $wrappedOnEvent = $this->wrapEventCallback($onEvent, $connectionState);
        $wrappedOnError = $this->wrapErrorCallback($onError, $connectionState);

        // Start the first connection attempt
        $this->attemptConnection($connectionState, $wrappedOnEvent, $wrappedOnError, $mainPromise);

        // The main promise's cancellation now controls the entire state machine.
        $mainPromise->setCancelHandler(function () use ($connectionState): void {
            $connectionState->cancel();
        });

        return $mainPromise;
    }

    /**
     * Attempts to establish an SSE connection.
     *
     * @param  SSEConnectionState<SSEResponse>  $connectionState
     * @param  CancellablePromise<SSEResponse>  $mainPromise
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
            // Ensure headers is an array
            if (! is_array($headers)) {
                $headers = [];
            }
            // Remove previous Last-Event-ID header if it exists to avoid duplicates
            $headers = array_filter($headers, function ($h): bool {
                if (! is_string($h)) {
                    return true;
                }

                return ! str_starts_with(strtolower($h), 'last-event-id:');
            });
            $headers[] = 'Last-Event-ID: ' . $connectionState->getLastEventId();
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        $connectionPromise = $this->createSSEConnection($connectionState->getUrl(), $options, $onEvent, $onError);
        $connectionState->setCurrentConnection($connectionPromise);

        $connectionPromise->then(
            /**
             * @param  mixed  $response
             */
            function ($response) use ($mainPromise, $connectionState): void {
                // Response is already typed as SSEResponse from the promise generic
                if ($connectionState->isCancelled()) {
                    return;
                }

                if (! $mainPromise->isSettled()) {
                    $mainPromise->resolve($response);
                }
                $connectionState->onConnected();
            },
            /**
             * @param  mixed  $error
             */
            function ($error) use ($mainPromise, $connectionState, $onEvent, $onError): void {
                if (! ($error instanceof \Throwable)) {
                    return;
                }

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

                // Convert Throwable to Exception for shouldReconnect
                $errorException = $error instanceof \Exception ? $error : new \Exception($error->getMessage(), (int)$error->getCode(), $error);

                if (! $connectionState->shouldReconnect($errorException)) {
                    if (! $mainPromise->isSettled()) {
                        $mainPromise->reject($error);
                    }

                    return;
                }

                $delay = $connectionState->getReconnectDelay();

                $onReconnect = $connectionState->getConfig()->onReconnect;
                if ($onReconnect !== null) {
                    $onReconnect($connectionState->getAttemptCount(), $delay, $error);
                }

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
     *
     * @param  array<int|string, mixed>  $options
     * @return CancellablePromiseInterface<SSEResponse>
     */
    private function createSSEConnection(
        string $url,
        array $options,
        ?callable $onEvent,
        ?callable $onError
    ): CancellablePromiseInterface {
        /** @var CancellablePromise<SSEResponse> $promise */
        $promise = new CancellablePromise();
        /** @var SSEResponse|null $sseResponse */
        $sseResponse = null;
        $headersProcessed = false;

        $curlOnlyOptions = array_filter($options, 'is_int', ARRAY_FILTER_USE_KEY);

        $existingHeaders = $curlOnlyOptions[CURLOPT_HTTPHEADER] ?? [];
        if (! is_array($existingHeaders)) {
            $existingHeaders = [];
        }

        $sseOptions = array_replace($curlOnlyOptions, [
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array_merge(
                $existingHeaders,
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
                        error_log('SSE event parsing error: ' . $e->getMessage());
                    }
                }

                return strlen($data);
            },
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use ($url, $promise, &$sseResponse, &$headersProcessed) {
                if ($promise->isSettled()) {
                    return strlen($header);
                }

                // Ensure $ch is a CurlHandle to satisfy phpstan
                if (! ($ch instanceof \CurlHandle)) {
                    return strlen($header);
                }

                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if (! $headersProcessed && $httpCode > 0) {
                    if ($httpCode >= 200 && $httpCode < 300) {
                        $tempStream = fopen('php://temp', 'r+');
                        if ($tempStream === false) {
                            $exception = new HttpStreamException(
                                'Failed to create temp stream',
                                0,
                                null,
                                $url
                            );
                            $exception->setStreamState('stream_creation_failed');
                            $promise->reject($exception);
                        } else {
                            $sseResponse = new SSEResponse(new Stream($tempStream), $httpCode, []);
                            $promise->resolve($sseResponse);
                        }
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

        $promise->setCancelHandler(function () use ($requestId): void {
            EventLoop::getInstance()->cancelHttpRequest($requestId);
        });

        return $promise;
    }

    /**
     * Wraps the event callback to handle last event ID tracking.
     *
     * @param  SSEConnectionState<SSEResponse>  $state
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
     *
     * @param  SSEConnectionState<SSEResponse>  $state
     */
    private function wrapErrorCallback(?callable $onError, SSEConnectionState $state): callable
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
