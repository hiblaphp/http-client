<?php

namespace Hibla\Http\Testing\Traits\RequestBuilder;

trait BuildsSSEMocks
{
    abstract protected function getRequest();
    abstract public function respondWithHeader(string $name, string $value): self;

    /**
     * Configure this mock as an SSE response.
     */
    public function respondWithSSE(array $events): self
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
    ): self {
        $eventData = array_filter([
            'data' => $data,
            'event' => $event,
            'id' => $id,
            'retry' => $retry,
        ], fn($v) => $v !== null);

        $this->getRequest()->addSSEEvent($eventData);

        return $this;
    }

    /**
     * Mock an SSE stream that sends keepalive events.
     */
    public function sseWithKeepalive(array $dataEvents, int $keepaliveCount = 3): self
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
    public function sseDisconnectAfter(int $eventsBeforeDisconnect, string $disconnectError = 'Connection reset'): self
    {
        $events = [];
        for ($i = 0; $i < $eventsBeforeDisconnect; $i++) {
            $events[] = [
                'data' => json_encode(['index' => $i]),
                'id' => (string)$i,
            ];
        }

        $this->respondWithSSE($events);
        $this->getRequest()->setError($disconnectError);
        $this->getRequest()->setRetryable(true);

        return $this;
    }

    /**
     * Mock an SSE stream with custom retry interval.
     */
    public function sseWithRetry(array $events, int $retryMs = 3000): self
    {
        if (!empty($events)) {
            $events[0]['retry'] = $retryMs;
        }

        return $this->respondWithSSE($events);
    }

    /**
     * Mock an SSE stream with multiple event types.
     */
    public function sseMultipleTypes(array $eventsByType): self
    {
        $events = [];
        foreach ($eventsByType as $type => $typeEvents) {
            foreach ($typeEvents as $data) {
                $events[] = [
                    'event' => $type,
                    'data' => is_array($data) ? json_encode($data) : $data,
                ];
            }
        }

        return $this->respondWithSSE($events);
    }

    /**
     * Mock an SSE stream with event IDs (useful for reconnection scenarios).
     */
    public function sseWithEventIds(array $eventsWithIds): self
    {
        $this->getRequest()->asSSE();
        $this->respondWithHeader('Content-Type', 'text/event-stream');
        $this->respondWithHeader('Cache-Control', 'no-cache');
        $this->respondWithHeader('Connection', 'keep-alive');
        
        foreach ($eventsWithIds as $event) {
            if (!isset($event['id'])) {
                throw new \InvalidArgumentException('All events must have an id field when using sseWithEventIds()');
            }
            $this->getRequest()->addSSEEvent($event);
        }

        return $this;
    }

    /**
     * Mock an SSE stream that expects Last-Event-ID header (for resumption).
     */
    public function sseExpectLastEventId(string $lastEventId, array $eventsAfterResume): self
    {
        $this->getRequest()->expectHeader('Last-Event-ID', $lastEventId);
        return $this->respondWithSSE($eventsAfterResume);
    }

    /**
     * Mock an SSE stream with server-sent retry directive.
     */
    public function sseWithRetryDirective(int $retryMs, array $events = []): self
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
     */
    public function sseWithComments(array $events, array $comments = []): self
    {
        $eventsWithComments = [];
        
        foreach ($events as $index => $event) {
            // Add comment before event if provided
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
    public function sseHeartbeatOnly(int $heartbeatCount = 10): self
    {
        $events = [];
        for ($i = 0; $i < $heartbeatCount; $i++) {
            $events[] = ['data' => ''];
        }

        return $this->respondWithSSE($events);
    }
}