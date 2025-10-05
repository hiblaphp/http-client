<?php

namespace Hibla\Http\Testing\Traits\RequestBuilder;

trait BuildsSSEMocks
{
    abstract protected function getRequest();

    abstract public function respondWithHeader(string $name, string $value): static;

    /**
     * Configure this mock as an SSE response.
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int, comment?: string}> $events
     */
    public function respondWithSSE(array $events): static
    {
        $this->getRequest()->asSSE();
        $this->getRequest()->setSSEEvents($events);

        $this->respondWithHeader('Content-Type', 'text/event-stream');
        $this->respondWithHeader('Cache-Control', 'no-cache');
        $this->respondWithHeader('Connection', 'keep-alive');

        return $this;
    }

    /**
     * Add a single SSE event to the mock.
     */
    public function addSSEEvent(
        ?string $data = null,
        ?string $event = null,
        ?string $id = null,
        ?int $retry = null
    ): static {
        $eventData = array_filter([
            'data' => $data,
            'event' => $event,
            'id' => $id,
            'retry' => $retry,
        ], fn ($v) => $v !== null);

        $this->getRequest()->addSSEEvent($eventData);

        return $this;
    }

    /**
     * Mock an SSE stream that sends keepalive events.
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $dataEvents
     */
    public function sseWithKeepalive(array $dataEvents, int $keepaliveCount = 3): static
    {
        $events = [];
        foreach ($dataEvents as $index => $event) {
            $events[] = $event;

            // Add keepalive events between data events (but not after the last one)
            if ($index < count($dataEvents) - 1) {
                for ($i = 0; $i < $keepaliveCount; $i++) {
                    $events[] = ['data' => '']; // Empty data = keepalive
                }
            }
        }

        return $this->respondWithSSE($events);
    }

    /**
     * Mock an SSE stream that disconnects after a certain number of events.
     */
    public function sseDisconnectAfter(int $eventsBeforeDisconnect, string $disconnectError = 'Connection reset'): static
    {
        $events = [];
        for ($i = 0; $i < $eventsBeforeDisconnect; $i++) {
            $jsonData = json_encode(['index' => $i]);
            if ($jsonData !== false) {
                $events[] = [
                    'data' => $jsonData,
                    'id' => (string)$i,
                ];
            }
        }

        $this->respondWithSSE($events);
        $this->getRequest()->setError($disconnectError);
        $this->getRequest()->setRetryable(true);

        return $this;
    }

    /**
     * Mock an SSE stream with custom retry interval.
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $events
     */
    public function sseWithRetry(array $events, int $retryMs = 3000): static
    {
        if ($events !== [] && isset($events[0])) {
            $events[0]['retry'] = $retryMs;
        }

        return $this->respondWithSSE($events);
    }

    /**
     * Mock an SSE stream with multiple event types.
     *
     * @param array<string, array<int, string|array<string, mixed>>> $eventsByType
     */
    public function sseMultipleTypes(array $eventsByType): static
    {
        $events = [];
        foreach ($eventsByType as $type => $typeEvents) {
            foreach ($typeEvents as $data) {
                $dataString = is_array($data) ? json_encode($data) : $data;
                if ($dataString !== false) {
                    $events[] = [
                        'event' => $type,
                        'data' => $dataString,
                    ];
                }
            }
        }

        return $this->respondWithSSE($events);
    }

    /**
     * Mock an SSE stream with event IDs (useful for reconnection scenarios).
     *
     * @param array<int, array{data?: string, event?: string, id: string, retry?: int}> $eventsWithIds
     */
    public function sseWithEventIds(array $eventsWithIds): static
    {
        $this->getRequest()->asSSE();
        $this->respondWithHeader('Content-Type', 'text/event-stream');
        $this->respondWithHeader('Cache-Control', 'no-cache');
        $this->respondWithHeader('Connection', 'keep-alive');

        foreach ($eventsWithIds as $event) {
            if (! isset($event['id'])) {
                throw new \InvalidArgumentException('All events must have an id field when using sseWithEventIds()');
            }
            $this->getRequest()->addSSEEvent($event);
        }

        return $this;
    }

    /**
     * Mock an SSE stream that expects Last-Event-ID header (for resumption).
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $eventsAfterResume
     */
    public function sseExpectLastEventId(string $lastEventId, array $eventsAfterResume): static
    {
        $this->getRequest()->addHeaderMatcher('Last-Event-ID', $lastEventId);

        return $this->respondWithSSE($eventsAfterResume);
    }

    /**
     * Mock an SSE stream with server-sent retry directive.
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $events
     */
    public function sseWithRetryDirective(int $retryMs, array $events = []): static
    {
        $this->getRequest()->asSSE();
        $this->respondWithHeader('Content-Type', 'text/event-stream');
        $this->respondWithHeader('Cache-Control', 'no-cache');
        $this->respondWithHeader('Connection', 'keep-alive');

        // Add retry directive as first event
        $retryEvent = ['retry' => $retryMs];
        $allEvents = array_merge([$retryEvent], $events);

        $this->getRequest()->setSSEEvents($allEvents);

        return $this;
    }

    /**
     * Mock an SSE stream with comment lines (for testing parser).
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $events
     * @param array<int, string> $comments
     */
    public function sseWithComments(array $events, array $comments = []): static
    {
        $eventsWithComments = [];

        foreach ($events as $index => $event) {
            if (isset($comments[$index])) {
                $eventsWithComments[] = ['comment' => $comments[$index]];
            }
            $eventsWithComments[] = $event;
        }

        return $this->respondWithSSE($eventsWithComments);
    }

    /**
     * Mock an SSE stream that sends only keepalive (heartbeat) events.
     */
    public function sseHeartbeatOnly(int $heartbeatCount = 10): static
    {
        $events = [];
        for ($i = 0; $i < $heartbeatCount; $i++) {
            $events[] = ['data' => ''];
        }

        return $this->respondWithSSE($events);
    }
}
