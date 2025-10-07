<?php

namespace Hibla\HttpClient\Testing\Utilities\Formatters;

use Hibla\HttpClient\SSE\SSEEvent;

class SSEEventFormatter
{
    /**
     * @param array<array{id?: string, event?: string, data?: string, retry?: int}> $events
     */
    public function formatEvents(array $events): string
    {
        $formatted = [];

        foreach ($events as $event) {
            $formatted[] = $this->formatSingleEvent($event);
        }

        return implode('', $formatted);
    }

    /**
     * @param array{id?: string, event?: string, data?: string, retry?: int} $event
     */
    private function formatSingleEvent(array $event): string
    {
        $lines = [];

        if (isset($event['id']) && is_string($event['id'])) {
            $lines[] = "id: {$event['id']}";
        }

        if (isset($event['event']) && is_string($event['event'])) {
            $lines[] = "event: {$event['event']}";
        }

        if (isset($event['retry']) && is_int($event['retry'])) {
            $lines[] = 'retry: ' . (string)$event['retry'];
        }

        if (isset($event['data']) && is_string($event['data'])) {
            $dataLines = explode("\n", $event['data']);
            foreach ($dataLines as $line) {
                $lines[] = "data: {$line}";
            }
        }

        return implode("\n", $lines) . "\n\n";
    }

    /**
     * @param array{id?: string, event?: string, data?: string, retry?: int} $eventData
     */
    public function createSSEEvent(array $eventData): SSEEvent
    {
        $rawFields = [];
        
        if (isset($eventData['id'])) {
            $rawFields['id'] = [$eventData['id']];
        }
        if (isset($eventData['event'])) {
            $rawFields['event'] = [$eventData['event']];
        }
        if (isset($eventData['data'])) {
            $rawFields['data'] = [$eventData['data']];
        }
        if (isset($eventData['retry'])) {
            $rawFields['retry'] = [(string)$eventData['retry']];
        }

        return new SSEEvent(
            id: $eventData['id'] ?? null,
            event: $eventData['event'] ?? null,
            data: $eventData['data'] ?? null,
            retry: $eventData['retry'] ?? null,
            rawFields: $rawFields
        );
    }
}