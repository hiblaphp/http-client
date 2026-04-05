<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Traits\RequestBuilder;

trait BuildsRealisticSSEMocks
{
    abstract protected function getRequest();

    abstract public function respondWithHeader(string $name, string $value): static;

    /**
     * Mock an SSE stream that emits a specific list of events.
     * 
     * Use ->dataStreamTransferLatency() to control the timing between events.
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $events
     */
    public function sseWithPeriodicEvents(array $events): static
    {
        $this->getRequest()->asSSE();
        $this->getRequest()->setSSEStreamConfig([
            'type' => 'periodic',
            'events' => $events,
        ]);

        $this->respondWithHeader('Content-Type', 'text/event-stream');
        $this->respondWithHeader('Cache-Control', 'no-cache');
        $this->respondWithHeader('Connection', 'keep-alive');

        return $this;
    }

    /**
     * Mock an SSE stream that emits a limited number of generated events then closes.
     * 
     * Use ->dataStreamTransferLatency() to control the timing between events.
     *
     * @param int $eventCount Number of events to send.
     * @param callable|null $eventGenerator Callback to generate event data: fn(int $index) => array
     */
    public function sseWithLimitedEvents(
        int $eventCount,
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
            'auto_close' => true,
        ]);

        $this->respondWithHeader('Content-Type', 'text/event-stream');
        $this->respondWithHeader('Cache-Control', 'no-cache');
        $this->respondWithHeader('Connection', 'keep-alive');

        return $this;
    }

    /**
     * Mock an infinite SSE stream (emits until the client cancels).
     * 
     * Use ->dataStreamTransferLatency() to control the timing between events.
     *
     * @param callable $eventGenerator Callback to generate events: fn(int $index) => array
     * @param int|null $maxEvents Optional maximum events to send before stopping.
     */
    public function sseInfiniteStream(
        callable $eventGenerator,
        ?int $maxEvents = null
    ): static {
        $this->getRequest()->asSSE();
        $this->getRequest()->setSSEStreamConfig([
            'type' => 'infinite',
            'event_generator' => $eventGenerator,
            'max_events' => $maxEvents,
        ]);

        $this->respondWithHeader('Content-Type', 'text/event-stream');
        $this->respondWithHeader('Cache-Control', 'no-cache');
        $this->respondWithHeader('Connection', 'keep-alive');

        return $this;
    }

    /**
     * Mock an SSE stream that emits N events and then simulates a network drop.
     *
     * @param int $eventCount Number of events before disconnect.
     * @param string $disconnectError Error message on disconnect.
     * @param callable|null $eventGenerator
     */
    public function ssePeriodicThenDisconnect(
        int $eventCount,
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