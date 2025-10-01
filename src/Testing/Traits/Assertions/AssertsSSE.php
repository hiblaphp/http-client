<?php

namespace Hibla\Http\Testing\Traits\Assertions;

use Hibla\Http\Testing\Exceptions\MockAssertionException;

trait AssertsSSE
{
    abstract public function getRequestHistory(): array;
    abstract public function getLastRequest();
    abstract public function getRequest(int $index);

    public function assertSSEConnectionMade(string $url): void
    {
        foreach ($this->getRequestHistory() as $request) {
            if ($request->getUrl() === $url || fnmatch($url, $request->getUrl())) {
                $accept = $request->getHeader('accept');
                if ($accept && (
                    (is_string($accept) && str_contains($accept, 'text/event-stream')) ||
                    (is_array($accept) && in_array('text/event-stream', $accept))
                )) {
                    return;
                }
            }
        }

        throw new MockAssertionException("Expected SSE connection to {$url} was not made");
    }

    public function assertNoSSEConnections(): void
    {
        foreach ($this->getRequestHistory() as $request) {
            $accept = $request->getHeader('accept');
            if ($accept && (
                (is_string($accept) && str_contains($accept, 'text/event-stream')) ||
                (is_array($accept) && in_array('text/event-stream', $accept))
            )) {
                throw new MockAssertionException(
                    "Expected no SSE connections, but found connection to: {$request->getUrl()}"
                );
            }
        }
    }

    public function assertSSELastEventId(string $expectedId, ?int $requestIndex = null): void
    {
        $request = $requestIndex === null
            ? $this->getLastRequest()
            : $this->getRequest($requestIndex);

        if ($request === null) {
            throw new MockAssertionException('No request found at the specified index');
        }

        $lastEventId = $request->getHeader('last-event-id');
        if ($lastEventId === null) {
            throw new MockAssertionException('Last-Event-ID header was not sent in the request');
        }

        $actualId = is_array($lastEventId) ? $lastEventId[0] : $lastEventId;
        if ($actualId !== $expectedId) {
            throw new MockAssertionException(
                "Last-Event-ID mismatch. Expected: '{$expectedId}', Got: '{$actualId}'"
            );
        }
    }
}