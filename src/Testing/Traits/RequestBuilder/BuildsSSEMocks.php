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
        $currentEvents = $this->getRequest()->getSSEEvents();
        $eventData = array_filter([
            'data' => $data,
            'event' => $event,
            'id' => $id,
            'retry' => $retry,
        ], fn($v) => $v !== null);

        $currentEvents[] = $eventData;
        $this->getRequest()->setSSEEvents($currentEvents);

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

            if ($index < count($dataEvents) - 1) {
                for ($i = 0; $i < $keepaliveCount; $i++) {
                    $events[] = ['data' => ''];
                }
            }
        }

        return $this->respondWithSSE($events);
    }

    /**
     * Mock an SSE stream that reconnects after a certain number of events.
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
}