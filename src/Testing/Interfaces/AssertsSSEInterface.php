<?php

namespace Hibla\HttpClient\Testing\Interfaces;

use Hibla\HttpClient\Testing\Exceptions\MockAssertionError;

interface AssertsSSEInterface
{
    /**
     * Assert that an SSE connection was made to the specified URL.
     *
     * @param string $url The URL that was connected to
     * @throws MockAssertionError
     */
    public function assertSSEConnectionMade(string $url): void;

    /**
     * Assert that no SSE connections were made.
     *
     * @throws MockAssertionError
     */
    public function assertNoSSEConnections(): void;

    /**
     * Assert that the Last-Event-ID header matches the expected value.
     *
     * @param string $expectedId Expected Last-Event-ID value
     * @param int|null $requestIndex Optional request index (null for last request)
     * @throws MockAssertionError
     */
    public function assertSSELastEventId(string $expectedId, ?int $requestIndex = null): void;

    /**
     * Assert that SSE connection was attempted a specific number of times.
     *
     * @param string $url The URL pattern
     * @param int $expectedAttempts Expected number of attempts
     * @throws MockAssertionError
     */
    public function assertSSEConnectionAttempts(string $url, int $expectedAttempts): void;

    /**
     * Assert that SSE connection was attempted at least a minimum number of times.
     *
     * @param string $url The URL pattern
     * @param int $minAttempts Minimum number of attempts
     * @throws MockAssertionError
     */
    public function assertSSEConnectionAttemptsAtLeast(string $url, int $minAttempts): void;

    /**
     * Assert that SSE connection was attempted at most a maximum number of times.
     *
     * @param string $url The URL pattern
     * @param int $maxAttempts Maximum number of attempts
     * @throws MockAssertionError
     */
    public function assertSSEConnectionAttemptsAtMost(string $url, int $maxAttempts): void;

    /**
     * Assert that SSE reconnection occurred with Last-Event-ID header.
     *
     * @param string $url The URL pattern
     * @throws MockAssertionError
     */
    public function assertSSEReconnectionOccurred(string $url): void;

    /**
     * Assert that SSE connection has specific header value.
     *
     * @param string $url The URL pattern
     * @param string $headerName Header name to check
     * @param string $expectedValue Expected header value
     * @throws MockAssertionError
     */
    public function assertSSEConnectionHasHeader(string $url, string $headerName, string $expectedValue): void;

    /**
     * Assert that SSE connection does not have a specific header.
     *
     * @param string $url The URL pattern
     * @param string $headerName Header name to check
     * @throws MockAssertionError
     */
    public function assertSSEConnectionMissingHeader(string $url, string $headerName): void;

    /**
     * Assert that multiple SSE connections were made to different URLs.
     *
     * @param array<string> $urls List of URLs
     * @throws MockAssertionError
     */
    public function assertSSEConnectionsMadeToMultipleUrls(array $urls): void;

    /**
     * Assert that SSE connections were made in a specific order.
     *
     * @param array<string> $urls List of URLs in expected order
     * @throws MockAssertionError
     */
    public function assertSSEConnectionsInOrder(array $urls): void;

    /**
     * Assert that SSE connection includes authentication header.
     *
     * @param string $url The URL pattern
     * @param string|null $expectedToken Optional token to verify
     * @throws MockAssertionError
     */
    public function assertSSEConnectionAuthenticated(string $url, ?string $expectedToken = null): void;

    /**
     * Assert that SSE reconnection attempts have increasing Last-Event-IDs.
     *
     * @param string $url The URL pattern
     * @throws MockAssertionError
     */
    public function assertSSEReconnectionProgression(string $url): void;

    /**
     * Assert that the first SSE connection has no Last-Event-ID header.
     *
     * @param string $url The URL pattern
     * @throws MockAssertionError
     */
    public function assertFirstSSEConnectionHasNoLastEventId(string $url): void;

    /**
     * Assert that SSE connection has proper cache control headers.
     *
     * @param string $url The URL pattern
     * @throws MockAssertionError
     */
    public function assertSSEConnectionRequestedWithProperHeaders(string $url): void;

    /**
     * Get all SSE connection attempts for a specific URL.
     *
     * @param string $url The URL pattern
     * @return array<int, \Hibla\HttpClient\Testing\Utilities\RecordedRequest>
     */
    public function getSSEConnectionAttempts(string $url): array;

    /**
     * Assert that SSE connection count matches expected for a URL pattern.
     *
     * @param string $url The URL pattern
     * @param int $expectedCount Expected number of connections
     * @throws MockAssertionError
     */
    public function assertSSEConnectionCount(string $url, int $expectedCount): void;
}
