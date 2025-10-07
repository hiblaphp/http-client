<?php

namespace Hibla\Http\Testing\Interfaces;

use Hibla\Http\Testing\Exceptions\MockAssertionException;
use Hibla\Http\Testing\Utilities\RecordedRequest;

/**
 * Interface for additional request assertions.
 */
interface AssertsRequestsExtendedInterface
{
    /**
     * Assert that a request was made with a specific URL pattern.
     *
     * @param string $method HTTP method
     * @param string $pattern URL pattern (fnmatch syntax)
     * @throws MockAssertionException
     */
    public function assertRequestMatchingUrl(string $method, string $pattern): void;

    /**
     * Assert that requests were made in a specific order.
     *
     * @param array<array{method: string, url: string}> $expectedSequence Expected sequence of requests
     * @throws MockAssertionException
     */
    public function assertRequestSequence(array $expectedSequence): void;

    /**
     * Assert that a request was made at a specific index in history.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param int $index Request index in history
     * @throws MockAssertionException
     */
    public function assertRequestAtIndex(string $method, string $url, int $index): void;

    /**
     * Assert that exactly one request was made to a URL.
     *
     * @param string $url Request URL
     * @throws MockAssertionException
     */
    public function assertSingleRequestTo(string $url): void;

    /**
     * Assert that a request was NOT made.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @throws MockAssertionException
     */
    public function assertRequestNotMade(string $method, string $url): void;

    /**
     * Assert that requests to a URL do not exceed a limit.
     *
     * @param string $url Request URL
     * @param int $maxCount Maximum allowed count
     * @throws MockAssertionException
     */
    public function assertRequestCountTo(string $url, int $maxCount): void;

    /**
     * Get all requests to a specific URL.
     *
     * @param string $url Request URL
     * @return array<int, RecordedRequest>
     */
    public function getRequestsTo(string $url): array;

    /**
     * Get all requests using a specific method.
     *
     * @param string $method HTTP method
     * @return array<int, RecordedRequest>
     */
    public function getRequestsByMethod(string $method): array;

    /**
     * Dump all requests with a specific method.
     *
     * @param string $method HTTP method
     * @return void
     */
    public function dumpRequestsByMethod(string $method): void;
}