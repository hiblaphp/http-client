<?php

namespace Hibla\Http\Testing\Traits\RequestBuilder;

trait BuildsRealisticSSEMocks
{
    abstract protected function getRequest();
    
    abstract public function respondWithHeader(string $name, string $value): static;

    /**
     * Mock an SSE stream that emits events periodically with realistic timing.
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $events
     * @param float $intervalSeconds Interval between events in seconds
     * @param float $jitter Random jitter to add/subtract (0.0 to 1.0, where 1.0 = 100% of interval)
     */
    public function sseWithPeriodicEvents(
        array $events,
        float $intervalSeconds = 1.0,
        float $jitter = 0.0
    ): static {
        $this->getRequest()->asSSE();
        $this->getRequest()->setSSEStreamConfig([
            'type' => 'periodic',
            'events' => $events,
            'interval' => $intervalSeconds,
            'jitter' => $jitter,
        ]);

        $this->respondWithHeader('Content-Type', 'text/event-stream');
        $this->respondWithHeader('Cache-Control', 'no-cache');
        $this->respondWithHeader('Connection', 'keep-alive');

        return $this;
    }

    /**
     * Mock an SSE stream that emits a limited number of events then closes.
     *
     * @param int $eventCount Number of events to send
     * @param float $intervalSeconds Interval between events
     * @param callable|null $eventGenerator Callback to generate event data: fn(int $index) => array
     */
    public function sseWithLimitedEvents(
        int $eventCount,
        float $intervalSeconds = 1.0,
        ?callable $eventGenerator = null
    ): static {
        $events = [];
        
        for ($i = 0; $i < $eventCount; $i++) {
            if ($eventGenerator !== null) {
                $events[] = $eventGenerator($i);
            } else {
                $data = json_encode(['index' => $i, 'timestamp' => time()]);
                if ($data !== false) {
                    $events[] = [
                        'data' => $data,
                        'id' => (string)$i,
                        'event' => 'message',
                    ];
                }
            }
        }

        $this->getRequest()->asSSE();
        $this->getRequest()->setSSEStreamConfig([
            'type' => 'periodic',
            'events' => $events,
            'interval' => $intervalSeconds,
            'jitter' => 0.0,
            'auto_close' => true,
        ]);

        $this->respondWithHeader('Content-Type', 'text/event-stream');
        $this->respondWithHeader('Cache-Control', 'no-cache');
        $this->respondWithHeader('Connection', 'keep-alive');

        return $this;
    }

    /**
     * Mock an infinite SSE stream (useful for long-polling tests).
     *
     * @param callable $eventGenerator Callback to generate events: fn(int $index) => array
     * @param float $intervalSeconds Interval between events
     * @param int|null $maxEvents Maximum events to send (null = infinite until cancelled)
     */
    public function sseInfiniteStream(
        callable $eventGenerator,
        float $intervalSeconds = 1.0,
        ?int $maxEvents = null
    ): static {
        $this->getRequest()->asSSE();
        $this->getRequest()->setSSEStreamConfig([
            'type' => 'infinite',
            'event_generator' => $eventGenerator,
            'interval' => $intervalSeconds,
            'max_events' => $maxEvents,
        ]);

        $this->respondWithHeader('Content-Type', 'text/event-stream');
        $this->respondWithHeader('Cache-Control', 'no-cache');
        $this->respondWithHeader('Connection', 'keep-alive');

        return $this;
    }

    /**
     * Mock an SSE stream that drops connection after sending N events.
     *
     * @param int $eventCount Number of events before disconnect
     * @param float $intervalSeconds Interval between events
     * @param string $disconnectError Error message on disconnect
     * @param callable|null $eventGenerator
     */
    public function ssePeriodicThenDisconnect(
        int $eventCount,
        float $intervalSeconds = 1.0,
        string $disconnectError = 'Connection lost',
        ?callable $eventGenerator = null
    ): static {
        $events = [];
        
        for ($i = 0; $i < $eventCount; $i++) {
            if ($eventGenerator !== null) {
                $events[] = $eventGenerator($i);
            } else {
                $data = json_encode(['index' => $i, 'timestamp' => time()]);
                if ($data !== false) {
                    $events[] = [
                        'data' => $data,
                        'id' => (string)$i,
                    ];
                }
            }
        }

        $this->getRequest()->asSSE();
        $this->getRequest()->setSSEStreamConfig([
            'type' => 'periodic',
            'events' => $events,
            'interval' => $intervalSeconds,
            'jitter' => 0.0,
            'auto_close' => true,
        ]);
        
        $this->getRequest()->setError($disconnectError);
        $this->getRequest()->setRetryable(true);

        $this->respondWithHeader('Content-Type', 'text/event-stream');
        $this->respondWithHeader('Cache-Control', 'no-cache');
        $this->respondWithHeader('Connection', 'keep-alive');

        return $this;
    }
}