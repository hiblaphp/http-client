<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Utilities\Factories\SSE;

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Exceptions\HttpStreamException;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\HttpClient\Stream;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\Formatters\SSEEventFormatter;
use Hibla\Promise\Promise;

class PeriodicSSEEmitter
{
    private SSEEventFormatter $formatter;

    public function __construct()
    {
        $this->formatter = new SSEEventFormatter();
    }

    /**
     * @param Promise<SSEResponse> $promise
     * @param MockedRequest $mock
     * @param callable|null $onEvent
     * @param callable|null $onError
     * @param string|null &$periodicTimerId
     */
    public function emit(
        Promise $promise,
        MockedRequest $mock,
        ?callable $onEvent,
        ?callable $onError,
        ?string &$periodicTimerId
    ): void {
        $config = $mock->getSSEStreamConfig();
        if ($config === null) {
            throw new \RuntimeException('SSE stream config is required');
        }

        $resource = fopen('php://temp', 'w+b');
        if ($resource === false) {
            throw new HttpStreamException('Failed to create temporary stream');
        }

        $stream = new Stream($resource);
        $sseResponse = new SSEResponse(
            $stream,
            $mock->getStatusCode(),
            $mock->getHeaders()
        );

        $promise->resolve($sseResponse);

        $type = $config['type'] ?? 'periodic';

        $interval = isset($config['interval']) && is_numeric($config['interval']) 
            ? (float) $config['interval'] 
            : $mock->getChunkDelay();

        if ($interval <= 0) {
            $interval = 0.01;
        }

        $jitter = isset($config['jitter']) && is_numeric($config['jitter']) 
            ? (float) $config['jitter'] 
            : $mock->getChunkJitter();

        if ($type === 'infinite' && isset($config['event_generator']) && is_callable($config['event_generator'])) {
            $this->setupInfiniteEmitter($config, $onEvent, $interval, $jitter, $periodicTimerId, $sseResponse);
        } else {
            $this->setupFiniteEmitter($config, $mock, $onEvent, $onError, $interval, $jitter, $periodicTimerId, $sseResponse);
        }
    }

    /**
     * Sets up an infinite event stream.
     */
    private function setupInfiniteEmitter(
        array $config,
        ?callable $onEvent,
        float $interval,
        float $jitter,
        ?string &$periodicTimerId,
        SSEResponse $sseResponse
    ): void {
        $eventGenerator = $config['event_generator'];
        $maxEvents = $config['max_events'] ?? null;
        $eventIndex = 0;

        $periodicTimerId = Loop::addPeriodicTimer(
            interval: $interval,
            callback: function () use (
                $eventGenerator,
                &$eventIndex,
                $maxEvents,
                $onEvent,
                $jitter,
                $interval,
                &$periodicTimerId,
                $sseResponse,
            ) {
                try {
                    $sseResponse->getBody()->tell();
                } catch (\Throwable) {
                    if ($periodicTimerId !== null) {
                        Loop::cancelTimer($periodicTimerId);
                        $periodicTimerId = null;
                    }

                    return;
                }

                if ($maxEvents !== null && $eventIndex >= $maxEvents) {
                    Loop::cancelTimer($periodicTimerId);
                    $periodicTimerId = null;

                    return;
                }

                $eventData = $eventGenerator($eventIndex);
                if (\is_array($eventData)) {
                    $formattedEvent = $this->formatter->formatEvents([$eventData]);

                    $sseResponse->getBody()->write($formattedEvent);

                    $parsedEvents = $sseResponse->parseEvents($formattedEvent);
                    foreach ($parsedEvents as $event) {
                        if ($onEvent !== null) {
                            $onEvent($event);
                        }
                    }
                }
                $eventIndex++;

                if ($jitter > 0) {
                    $this->applyJitter($jitter, $interval);
                }
            },
            maxExecutions: $maxEvents
        );
    }

    /**
     * Sets up a finite stream from a predefined list of events.
     */
    private function setupFiniteEmitter(
        array $config,
        MockedRequest $mock,
        ?callable $onEvent,
        ?callable $onError,
        float $interval,
        float $jitter,
        ?string &$periodicTimerId,
        SSEResponse $sseResponse
    ): void {
        $rawEvents = $config['events'] ?? [];
        if (!is_array($rawEvents)) {
            $rawEvents = [];
        }

        $events = array_values(array_filter($rawEvents, 'is_array'));
        
        $eventIndex = 0;
        $totalEvents = count($events);
        $autoClose = $config['auto_close'] ?? false;

        $maxExecutions = $autoClose ? $totalEvents + 1 : $totalEvents;

        $periodicTimerId = Loop::addPeriodicTimer(
            interval: $interval,
            callback: function () use (
                &$events,
                &$eventIndex,
                &$totalEvents,
                $onEvent,
                $onError,
                $mock,
                $autoClose,
                $jitter,
                $interval,
                &$periodicTimerId,
                $sseResponse
            ) {
                try {
                    $sseResponse->getBody()->tell();
                } catch (\Throwable) {
                    if ($periodicTimerId !== null) {
                        Loop::cancelTimer($periodicTimerId);
                        $periodicTimerId = null;
                    }

                    return;
                }

                if ($eventIndex >= $totalEvents) {
                    Loop::cancelTimer($periodicTimerId);
                    $periodicTimerId = null;

                    if ($mock->shouldFail() && $autoClose) {
                        $error = $mock->getError() ?? 'Connection closed';
                        if ($onError !== null) {
                            $onError($error);
                        }
                    }

                    return;
                }

                $eventData = $events[$eventIndex];
                $formattedEvent = $this->formatter->formatEvents([$eventData]);

                $sseResponse->getBody()->write($formattedEvent);

                $parsedEvents = $sseResponse->parseEvents($formattedEvent);
                foreach ($parsedEvents as $event) {
                    if ($onEvent !== null) {
                        $onEvent($event);
                    }
                }
                $eventIndex++;

                if ($jitter > 0) {
                    $this->applyJitter($jitter, $interval);
                }
            },
            maxExecutions: $maxExecutions
        );
    }

    private function applyJitter(float $jitter, float $interval): void
    {
        if ($jitter <= 0 || $interval <= 0) {
            return;
        }
        $jitterAmount = $interval * $jitter;
        $randomJitter = (mt_rand() / mt_getrandmax() * 2 * $jitterAmount) - $jitterAmount;
        if ($randomJitter > 0) {
            usleep((int)($randomJitter * 1000000));
        }
    }
}