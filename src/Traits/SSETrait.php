<?php

namespace Hibla\Http\Traits;

use Hibla\Http\SSE\SSEEvent;

trait SSETrait
{
    /**
     * Wrap the SSE event callback to return data in the configured format.
     */
    private function wrapSSECallback(?callable $originalCallback): ?callable
    {
        if ($originalCallback === null || $this->sseDataFormat === null) {
            return $originalCallback;
        }

        return function (SSEEvent $event) use ($originalCallback) {
            $processedData = match ($this->sseDataFormat) {
                'json' => $this->parseEventDataAsJson($event),
                'array' => $this->eventToArrayWithParsedData($event),
                'raw' => $event->data,
                'event' => $event,
                default => $event
            };

            if ($this->sseMapper !== null) {
                $processedData = call_user_func($this->sseMapper, $processedData);
            }

            $originalCallback($processedData);
        };
    }

    /**
     * Convert event to array with automatically parsed JSON data.
     * If not valid JSON, keep original string
     */
    private function eventToArrayWithParsedData(SSEEvent $event): array
    {
        $array = $event->toArray();

        if ($array['data'] !== null) {
            $parsed = json_decode($array['data'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $array['data'] = $parsed;
            }
        }

        return $array;
    }

    /**
     * Parse event data as JSON, fallback to raw data.
     */
    private function parseEventDataAsJson(SSEEvent $event): mixed
    {
        if ($event->data === null) {
            return null;
        }

        $parsed = json_decode($event->data, true);
        return json_last_error() === JSON_ERROR_NONE ? $parsed : $event->data;
    }
}