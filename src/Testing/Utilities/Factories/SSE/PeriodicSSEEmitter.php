<?php

namespace Hibla\HttpClient\Testing\Utilities\Factories\SSE;

use Hibla\EventLoop\EventLoop;
use Hibla\HttpClient\Exceptions\HttpStreamException;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\HttpClient\Stream;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\Formatters\SSEEventFormatter;
use Hibla\Promise\CancellablePromise;

class PeriodicSSEEmitter
{
    private SSEEventFormatter $formatter;

    public function __construct()
    {
        $this->formatter = new SSEEventFormatter();
    }

    /**
     * @param CancellablePromise<SSEResponse> $promise
     * @param MockedRequest $mock
     * @param callable|null $onEvent
     * @param callable|null $onError
     * @param string|null &$periodicTimerId
     * @param-out string $periodicTimerId
     */
    public function emit(
        CancellablePromise $promise,
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
        $interval = $this->getConfigValue($config, 'interval', 1.0);
        $jitter = $this->getConfigValue($config, 'jitter', 0.0);

        if ($type === 'infinite' && isset($config['event_generator']) && is_callable($config['event_generator'])) {
            $this->setupInfiniteEmitter($config, $onEvent, $interval, $jitter, $periodicTimerId);
        } else {
            $this->setupFiniteEmitter($config, $mock, $onEvent, $onError, $interval, $jitter, $periodicTimerId);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param callable|null $onEvent
     * @param float $interval
     * @param float $jitter
     * @param string|null &$periodicTimerId
     * @param-out string $periodicTimerId
     */
    private function setupInfiniteEmitter(
        array $config,
        ?callable $onEvent,
        float $interval,
        float $jitter,
        ?string &$periodicTimerId
    ): void {
        if (!isset($config['event_generator'])) {
            return;
        }

        $eventGenerator = $config['event_generator'];
        
        // Validate it's callable
        if (!is_callable($eventGenerator)) {
            return;
        }

        $maxEvents = isset($config['max_events']) && is_int($config['max_events']) ? $config['max_events'] : null;
        $eventIndex = 0;

        $periodicTimerId = EventLoop::getInstance()->addPeriodicTimer(
            interval: $interval,
            callback: function () use (
                $eventGenerator,
                &$eventIndex,
                $maxEvents,
                $onEvent,
                $jitter,
                $interval,
                &$periodicTimerId
            ) {
                if ($maxEvents !== null && $eventIndex >= $maxEvents) {
                    if ($periodicTimerId !== null) {
                        EventLoop::getInstance()->cancelTimer($periodicTimerId);
                        $periodicTimerId = null;
                    }
                    return;
                }

                // Safe to call now since we validated it's callable
                /** @var callable $eventGenerator */
                $eventData = $eventGenerator($eventIndex);
                if (is_array($eventData)) {
                    /** @var array{data?: string, event?: string, id?: string, retry?: int} $eventData */
                    $event = $this->formatter->createSSEEvent($eventData);
                    if ($onEvent !== null) {
                        $onEvent($event);
                    }
                }
                $eventIndex++;

                $this->applyJitter($jitter, $interval);
            },
            maxExecutions: $maxEvents
        );
    }

    /**
     * @param array<string, mixed> $config
     * @param MockedRequest $mock
     * @param callable|null $onEvent
     * @param callable|null $onError
     * @param float $interval
     * @param float $jitter
     * @param string|null &$periodicTimerId
     * @param-out string $periodicTimerId
     */
    private function setupFiniteEmitter(
        array $config,
        MockedRequest $mock,
        ?callable $onEvent,
        ?callable $onError,
        float $interval,
        float $jitter,
        ?string &$periodicTimerId
    ): void {
        $events = $config['events'] ?? [];
        if (!is_array($events)) {
            $events = [];
        }

        /** @var array<array{data?: string, event?: string, id?: string, retry?: int}> $validatedEvents */
        $validatedEvents = [];
        foreach ($events as $event) {
            if (is_array($event)) {
                $validatedEvents[] = $event;
            }
        }
        $events = $validatedEvents;
        
        $eventIndex = 0;
        $totalEvents = count($events);
        $autoClose = isset($config['auto_close']) && is_bool($config['auto_close']) ? $config['auto_close'] : false;

        $periodicTimerId = EventLoop::getInstance()->addPeriodicTimer(
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
                &$periodicTimerId
            ) {
                if ($eventIndex >= $totalEvents) {
                    if ($periodicTimerId !== null) {
                        EventLoop::getInstance()->cancelTimer($periodicTimerId);
                        $periodicTimerId = null;
                    }

                    if ($mock->shouldFail() && $autoClose) {
                        $error = $mock->getError() ?? 'Connection closed';
                        if ($onError !== null) {
                            $onError($error);
                        }
                    }
                    return;
                }

                $eventData = $events[$eventIndex];
                /** @var array{data?: string, event?: string, id?: string, retry?: int} $eventData */
                $event = $this->formatter->createSSEEvent($eventData);
                if ($onEvent !== null) {
                    $onEvent($event);
                }
                $eventIndex++;

                $this->applyJitter($jitter, $interval);
            },
            maxExecutions: $totalEvents
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function getConfigValue(array $config, string $key, float $default): float
    {
        if (!isset($config[$key])) {
            return $default;
        }

        $value = $config[$key];
        if (is_float($value) || is_int($value)) {
            return (float)$value;
        }

        return $default;
    }

    private function applyJitter(float $jitter, float $interval): void
    {
        if ($jitter <= 0) {
            return;
        }

        $jitterAmount = $interval * $jitter;
        $randomJitter = (mt_rand() / mt_getrandmax()) * 2 * $jitterAmount - $jitterAmount;
        
        if ($randomJitter > 0) {
            usleep((int)($randomJitter * 1000000));
        }
    }
}