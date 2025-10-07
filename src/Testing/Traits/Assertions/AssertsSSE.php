<?php

namespace Hibla\HttpClient\Testing\Traits\Assertions;

use Hibla\HttpClient\Testing\Exceptions\MockAssertionException;

trait AssertsSSE
{
    /**
     * @return array<int, \Hibla\HttpClient\Testing\Utilities\RecordedRequest>
     */
    abstract public function getRequestHistory(): array;

    abstract public function getLastRequest();

    abstract public function getRequest(int $index);

    /**
     * Assert that an SSE connection was made to the specified URL.
     */
    public function assertSSEConnectionMade(string $url): void
    {
        foreach ($this->getRequestHistory() as $request) {
            if ($request->getUrl() === $url || fnmatch($url, $request->getUrl())) {
                $accept = $request->getHeader('accept');
                if ($accept !== null && (
                    (is_string($accept) && str_contains($accept, 'text/event-stream')) ||
                    (is_array($accept) && in_array('text/event-stream', $accept, true))
                )) {
                    return;
                }
            }
        }

        throw new MockAssertionException("Expected SSE connection to {$url} was not made");
    }

    /**
     * Assert that no SSE connections were made.
     */
    public function assertNoSSEConnections(): void
    {
        foreach ($this->getRequestHistory() as $request) {
            $accept = $request->getHeader('accept');
            if ($accept !== null && (
                (is_string($accept) && str_contains($accept, 'text/event-stream')) ||
                (is_array($accept) && in_array('text/event-stream', $accept, true))
            )) {
                throw new MockAssertionException(
                    "Expected no SSE connections, but found connection to: {$request->getUrl()}"
                );
            }
        }
    }

    /**
     * Assert that the Last-Event-ID header matches the expected value.
     */
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

    /**
     * Assert that SSE connection was attempted a specific number of times.
     */
    public function assertSSEConnectionAttempts(string $url, int $expectedAttempts): void
    {
        $actualAttempts = 0;

        foreach ($this->getRequestHistory() as $request) {
            if ($request->getUrl() === $url || fnmatch($url, $request->getUrl())) {
                $accept = $request->getHeader('accept');
                if ($accept !== null && (
                    (is_string($accept) && str_contains($accept, 'text/event-stream')) ||
                    (is_array($accept) && in_array('text/event-stream', $accept, true))
                )) {
                    $actualAttempts++;
                }
            }
        }

        if ($actualAttempts !== $expectedAttempts) {
            throw new MockAssertionException(
                "Expected {$expectedAttempts} SSE connection attempt(s) to {$url}, but found {$actualAttempts}"
            );
        }
    }

    /**
     * Assert that SSE connection was attempted at least a minimum number of times.
     */
    public function assertSSEConnectionAttemptsAtLeast(string $url, int $minAttempts): void
    {
        $actualAttempts = 0;

        foreach ($this->getRequestHistory() as $request) {
            if ($request->getUrl() === $url || fnmatch($url, $request->getUrl())) {
                $accept = $request->getHeader('accept');
                if ($accept !== null && (
                    (is_string($accept) && str_contains($accept, 'text/event-stream')) ||
                    (is_array($accept) && in_array('text/event-stream', $accept, true))
                )) {
                    $actualAttempts++;
                }
            }
        }

        if ($actualAttempts < $minAttempts) {
            throw new MockAssertionException(
                "Expected at least {$minAttempts} SSE connection attempt(s) to {$url}, but found {$actualAttempts}"
            );
        }
    }

    /**
     * Assert that SSE connection was attempted at most a maximum number of times.
     */
    public function assertSSEConnectionAttemptsAtMost(string $url, int $maxAttempts): void
    {
        $actualAttempts = 0;

        foreach ($this->getRequestHistory() as $request) {
            if ($request->getUrl() === $url || fnmatch($url, $request->getUrl())) {
                $accept = $request->getHeader('accept');
                if ($accept !== null && (
                    (is_string($accept) && str_contains($accept, 'text/event-stream')) ||
                    (is_array($accept) && in_array('text/event-stream', $accept, true))
                )) {
                    $actualAttempts++;
                }
            }
        }

        if ($actualAttempts > $maxAttempts) {
            throw new MockAssertionException(
                "Expected at most {$maxAttempts} SSE connection attempt(s) to {$url}, but found {$actualAttempts}"
            );
        }
    }

    /**
     * Assert that SSE reconnection occurred with Last-Event-ID header.
     */
    public function assertSSEReconnectionOccurred(string $url): void
    {
        $hasReconnection = false;

        foreach ($this->getRequestHistory() as $request) {
            if ($request->getUrl() === $url || fnmatch($url, $request->getUrl())) {
                $accept = $request->getHeader('accept');
                $lastEventId = $request->getHeader('last-event-id');
                
                if ($accept !== null && $lastEventId !== null && (
                    (is_string($accept) && str_contains($accept, 'text/event-stream')) ||
                    (is_array($accept) && in_array('text/event-stream', $accept, true))
                )) {
                    $hasReconnection = true;
                    break;
                }
            }
        }

        if (!$hasReconnection) {
            throw new MockAssertionException(
                "Expected SSE reconnection with Last-Event-ID header to {$url}, but none found"
            );
        }
    }

    /**
     * Assert that SSE connection has specific header value.
     */
    public function assertSSEConnectionHasHeader(string $url, string $headerName, string $expectedValue): void
    {
        foreach ($this->getRequestHistory() as $request) {
            if ($request->getUrl() === $url || fnmatch($url, $request->getUrl())) {
                $accept = $request->getHeader('accept');
                if ($accept !== null && (
                    (is_string($accept) && str_contains($accept, 'text/event-stream')) ||
                    (is_array($accept) && in_array('text/event-stream', $accept, true))
                )) {
                    $headerValue = $request->getHeader($headerName);
                    if ($headerValue === null) {
                        throw new MockAssertionException(
                            "SSE connection to {$url} does not have header '{$headerName}'"
                        );
                    }

                    $actualValue = is_array($headerValue) ? $headerValue[0] : $headerValue;
                    if ($actualValue !== $expectedValue) {
                        throw new MockAssertionException(
                            "SSE connection header '{$headerName}' mismatch. Expected: '{$expectedValue}', Got: '{$actualValue}'"
                        );
                    }
                    return;
                }
            }
        }

        throw new MockAssertionException("No SSE connection found to {$url}");
    }

    /**
     * Assert that SSE connection does not have a specific header.
     */
    public function assertSSEConnectionMissingHeader(string $url, string $headerName): void
    {
        foreach ($this->getRequestHistory() as $request) {
            if ($request->getUrl() === $url || fnmatch($url, $request->getUrl())) {
                $accept = $request->getHeader('accept');
                if ($accept !== null && (
                    (is_string($accept) && str_contains($accept, 'text/event-stream')) ||
                    (is_array($accept) && in_array('text/event-stream', $accept, true))
                )) {
                    $headerValue = $request->getHeader($headerName);
                    if ($headerValue !== null) {
                        throw new MockAssertionException(
                            "SSE connection to {$url} should not have header '{$headerName}', but it was found"
                        );
                    }
                    return;
                }
            }
        }

        throw new MockAssertionException("No SSE connection found to {$url}");
    }

    /**
     * Assert that multiple SSE connections were made to different URLs.
     * 
     * @param array<string> $urls
     */
    public function assertSSEConnectionsMadeToMultipleUrls(array $urls): void
    {
        $foundUrls = [];

        foreach ($this->getRequestHistory() as $request) {
            $accept = $request->getHeader('accept');
            if ($accept !== null && (
                (is_string($accept) && str_contains($accept, 'text/event-stream')) ||
                (is_array($accept) && in_array('text/event-stream', $accept, true))
            )) {
                foreach ($urls as $url) {
                    if (($request->getUrl() === $url || fnmatch($url, $request->getUrl())) 
                        && !in_array($url, $foundUrls, true)) {
                        $foundUrls[] = $url;
                    }
                }
            }
        }

        $missingUrls = array_diff($urls, $foundUrls);
        if ($missingUrls !== []) {
            throw new MockAssertionException(
                "Expected SSE connections to all URLs, but missing: " . implode(', ', $missingUrls)
            );
        }
    }

    /**
     * Assert that SSE connections were made in a specific order.
     * 
     * @param array<string> $urls
     */
    public function assertSSEConnectionsInOrder(array $urls): void
    {
        $sseRequests = [];

        foreach ($this->getRequestHistory() as $request) {
            $accept = $request->getHeader('accept');
            if ($accept !== null && (
                (is_string($accept) && str_contains($accept, 'text/event-stream')) ||
                (is_array($accept) && in_array('text/event-stream', $accept, true))
            )) {
                $sseRequests[] = $request->getUrl();
            }
        }

        $matchedCount = 0;
        $sseIndex = 0;

        foreach ($urls as $expectedUrl) {
            $found = false;
            for ($i = $sseIndex; $i < count($sseRequests); $i++) {
                if ($sseRequests[$i] === $expectedUrl || fnmatch($expectedUrl, $sseRequests[$i])) {
                    $matchedCount++;
                    $sseIndex = $i + 1;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                throw new MockAssertionException(
                    "SSE connections not in expected order. Expected '{$expectedUrl}' after position {$matchedCount}"
                );
            }
        }
    }

    /**
     * Assert that SSE connection includes authentication header.
     */
    public function assertSSEConnectionAuthenticated(string $url, ?string $expectedToken = null): void
    {
        foreach ($this->getRequestHistory() as $request) {
            if ($request->getUrl() === $url || fnmatch($url, $request->getUrl())) {
                $accept = $request->getHeader('accept');
                if ($accept !== null && (
                    (is_string($accept) && str_contains($accept, 'text/event-stream')) ||
                    (is_array($accept) && in_array('text/event-stream', $accept, true))
                )) {
                    $authHeader = $request->getHeader('authorization');
                    if ($authHeader === null) {
                        throw new MockAssertionException(
                            "SSE connection to {$url} missing Authorization header"
                        );
                    }

                    if ($expectedToken !== null) {
                        $actualToken = is_array($authHeader) ? $authHeader[0] : $authHeader;
                        if (!str_contains($actualToken, $expectedToken)) {
                            throw new MockAssertionException(
                                "SSE connection Authorization token mismatch. Expected token containing '{$expectedToken}', Got: '{$actualToken}'"
                            );
                        }
                    }
                    return;
                }
            }
        }

        throw new MockAssertionException("No SSE connection found to {$url}");
    }

    /**
     * Assert that SSE reconnection attempts have increasing Last-Event-IDs.
     */
    public function assertSSEReconnectionProgression(string $url): void
    {
        $eventIds = [];

        foreach ($this->getRequestHistory() as $request) {
            if ($request->getUrl() === $url || fnmatch($url, $request->getUrl())) {
                $accept = $request->getHeader('accept');
                if ($accept !== null && (
                    (is_string($accept) && str_contains($accept, 'text/event-stream')) ||
                    (is_array($accept) && in_array('text/event-stream', $accept, true))
                )) {
                    $lastEventId = $request->getHeader('last-event-id');
                    if ($lastEventId !== null) {
                        $eventIds[] = is_array($lastEventId) ? $lastEventId[0] : $lastEventId;
                    }
                }
            }
        }

        if (count($eventIds) < 2) {
            throw new MockAssertionException(
                "Not enough SSE reconnections with Last-Event-ID to verify progression. Found: " . count($eventIds)
            );
        }

        // Check if event IDs are sequential (assuming numeric IDs)
        for ($i = 1; $i < count($eventIds); $i++) {
            if (is_numeric($eventIds[$i]) && is_numeric($eventIds[$i - 1])) {
                if ((int)$eventIds[$i] <= (int)$eventIds[$i - 1]) {
                    throw new MockAssertionException(
                        "SSE reconnection Last-Event-IDs are not progressing. " .
                        "Event ID {$eventIds[$i]} at position {$i} is not greater than previous {$eventIds[$i - 1]}"
                    );
                }
            }
        }
    }

    /**
     * Assert that the first SSE connection has no Last-Event-ID header.
     */
    public function assertFirstSSEConnectionHasNoLastEventId(string $url): void
    {
        $foundFirst = false;

        foreach ($this->getRequestHistory() as $request) {
            if ($request->getUrl() === $url || fnmatch($url, $request->getUrl())) {
                $accept = $request->getHeader('accept');
                if ($accept !== null && (
                    (is_string($accept) && str_contains($accept, 'text/event-stream')) ||
                    (is_array($accept) && in_array('text/event-stream', $accept, true))
                )) {
                    $lastEventId = $request->getHeader('last-event-id');
                    if ($lastEventId !== null) {
                        throw new MockAssertionException(
                            "First SSE connection to {$url} should not have Last-Event-ID header, but found: " .
                            (is_array($lastEventId) ? $lastEventId[0] : $lastEventId)
                        );
                    }
                    $foundFirst = true;
                    break;
                }
            }
        }

        if (!$foundFirst) {
            throw new MockAssertionException("No SSE connection found to {$url}");
        }
    }

    /**
     * Assert that SSE connection has Cache-Control: no-cache header in response.
     * Note: This checks the request was made, actual response headers would need to be tracked separately.
     */
    public function assertSSEConnectionRequestedWithProperHeaders(string $url): void
    {
        foreach ($this->getRequestHistory() as $request) {
            if ($request->getUrl() === $url || fnmatch($url, $request->getUrl())) {
                $accept = $request->getHeader('accept');
                if ($accept !== null && (
                    (is_string($accept) && str_contains($accept, 'text/event-stream')) ||
                    (is_array($accept) && in_array('text/event-stream', $accept, true))
                )) {
                    // Verify the request has proper SSE headers
                    $cacheControl = $request->getHeader('cache-control');
                    if ($cacheControl !== null) {
                        $cacheValue = is_array($cacheControl) ? $cacheControl[0] : $cacheControl;
                        if (!str_contains(strtolower($cacheValue), 'no-cache') && 
                            !str_contains(strtolower($cacheValue), 'no-store')) {
                            throw new MockAssertionException(
                                "SSE connection to {$url} should have Cache-Control: no-cache or no-store"
                            );
                        }
                    }
                    return;
                }
            }
        }

        throw new MockAssertionException("No SSE connection found to {$url}");
    }

    /**
     * Get all SSE connection attempts for a specific URL.
     * 
     * @return array<int, \Hibla\HttpClient\Testing\Utilities\RecordedRequest>
     */
    public function getSSEConnectionAttempts(string $url): array
    {
        $attempts = [];

        foreach ($this->getRequestHistory() as $request) {
            if ($request->getUrl() === $url || fnmatch($url, $request->getUrl())) {
                $accept = $request->getHeader('accept');
                if ($accept !== null && (
                    (is_string($accept) && str_contains($accept, 'text/event-stream')) ||
                    (is_array($accept) && in_array('text/event-stream', $accept, true))
                )) {
                    $attempts[] = $request;
                }
            }
        }

        return $attempts;
    }

    /**
     * Assert that SSE connection count matches expected for a URL pattern.
     */
    public function assertSSEConnectionCount(string $url, int $expectedCount): void
    {
        $attempts = $this->getSSEConnectionAttempts($url);
        $actualCount = count($attempts);

        if ($actualCount !== $expectedCount) {
            throw new MockAssertionException(
                "Expected {$expectedCount} SSE connection(s) to {$url}, but found {$actualCount}"
            );
        }
    }
}