<?php

namespace Hibla\HttpClient\Testing\Traits\Assertions;

use Hibla\HttpClient\Testing\Utilities\RecordedRequest;

/**
 * Additional request assertions to complement AssertsRequests trait.
 */
trait AssertsRequestsExtended
{
    use AssertionHandler;

    /**
     * @return array<int, RecordedRequest>
     */
    abstract public function getRequestHistory(): array;

    /**
     * Assert that a request was made with a specific URL pattern.
     *
     * @param string $method HTTP method
     * @param string $pattern URL pattern (fnmatch syntax)
     */
    public function assertRequestMatchingUrl(string $method, string $pattern): void
    {
        foreach ($this->getRequestHistory() as $request) {
            if (
                strtoupper($request->getMethod()) === strtoupper($method) &&
                fnmatch($pattern, $request->getUrl())
            ) {
                return;
            }
        }

        $this->failAssertion(
            "Expected request not found: {$method} matching {$pattern}"
        );
    }

    /**
     * Assert that requests were made in a specific order.
     *
     * @param array<array{method: string, url: string}> $expectedSequence Expected sequence of requests
     */
    public function assertRequestSequence(array $expectedSequence): void
    {
        $history = $this->getRequestHistory();
        $historyCount = count($history);
        $expectedCount = count($expectedSequence);

        if ($historyCount < $expectedCount) {
            $this->failAssertion(
                "Expected at least {$expectedCount} requests, but only {$historyCount} were made"
            );
        }

        $matchIndex = 0;
        foreach ($history as $request) {
            if ($matchIndex >= $expectedCount) {
                break;
            }

            $expected = $expectedSequence[$matchIndex];
            if (
                strtoupper($request->getMethod()) === strtoupper($expected['method']) &&
                $request->getUrl() === $expected['url']
            ) {
                $matchIndex++;
            }
        }

        if ($matchIndex !== $expectedCount) {
            $this->failAssertion(
                "Expected request sequence not found. Matched {$matchIndex} of {$expectedCount} requests"
            );
        }
    }

    /**
     * Assert that a request was made within a time range.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param int $index Request index in history
     */
    public function assertRequestAtIndex(string $method, string $url, int $index): void
    {
        $history = $this->getRequestHistory();

        if (!isset($history[$index])) {
            $this->failAssertion(
                "No request found at index {$index}"
            );
        }

        $request = $history[$index];

        if (
            strtoupper($request->getMethod()) !== strtoupper($method) ||
            $request->getUrl() !== $url
        ) {
            $this->failAssertion(
                "Request at index {$index} does not match: {$method} {$url}"
            );
        }
    }

    /**
     * Assert that exactly one request was made to a URL.
     *
     * @param string $url Request URL
     */
    public function assertSingleRequestTo(string $url): void
    {
        $count = 0;

        foreach ($this->getRequestHistory() as $request) {
            if ($request->getUrl() === $url) {
                $count++;
            }
        }

        if ($count === 0) {
            $this->failAssertion(
                "No requests found to: {$url}"
            );
        }

        if ($count > 1) {
            $this->failAssertion(
                "Expected single request to {$url}, but {$count} were made"
            );
        }
    }

    /**
     * Assert that a request was NOT made.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     */
    public function assertRequestNotMade(string $method, string $url): void
    {
        foreach ($this->getRequestHistory() as $request) {
            if (
                strtoupper($request->getMethod()) === strtoupper($method) &&
                $request->getUrl() === $url
            ) {
                $this->failAssertion(
                    "Unexpected request found: {$method} {$url}"
                );
            }
        }
    }

    /**
     * Assert that requests to a URL do not exceed a limit.
     *
     * @param string $url Request URL
     * @param int $maxCount Maximum allowed count
     */
    public function assertRequestCountTo(string $url, int $maxCount): void
    {
        $count = 0;

        foreach ($this->getRequestHistory() as $request) {
            if ($request->getUrl() === $url) {
                $count++;
            }
        }

        if ($count > $maxCount) {
            $this->failAssertion(
                "Expected at most {$maxCount} requests to {$url}, but {$count} were made"
            );
        }
    }

    /**
     * Get all requests to a specific URL.
     *
     * @param string $url Request URL
     * @return array<int, RecordedRequest>
     */
    public function getRequestsTo(string $url): array
    {
        $requests = [];

        foreach ($this->getRequestHistory() as $request) {
            if ($request->getUrl() === $url) {
                $requests[] = $request;
            }
        }

        return $requests;
    }

    /**
     * Get all requests using a specific method.
     *
     * @param string $method HTTP method
     * @return array<int, RecordedRequest>
     */
    public function getRequestsByMethod(string $method): array
    {
        $requests = [];

        foreach ($this->getRequestHistory() as $request) {
            if (strtoupper($request->getMethod()) === strtoupper($method)) {
                $requests[] = $request;
            }
        }

        return $requests;
    }

    /**
     * Dump all requests with a specific method.
     *
     * @param string $method HTTP method
     * @return void
     */
    public function dumpRequestsByMethod(string $method): void
    {
        $requests = $this->getRequestsByMethod($method);

        if ($requests === []) {
            echo "No {$method} requests recorded\n";
            return;
        }

        echo "=== {$method} Requests (" . count($requests) . ") ===\n";

        foreach ($requests as $index => $request) {
            echo "\n[{$index}] {$request->getUrl()}\n";

            $headers = $request->getHeaders();
            if ($headers !== []) {
                echo "  Headers:\n";
                foreach ($headers as $name => $value) {
                    $displayValue = is_array($value) ? implode(', ', $value) : $value;
                    echo "    {$name}: {$displayValue}\n";
                }
            }

            $body = $request->getBody();
            if ($body !== null && $body !== '') {
                echo "  Body: " . substr($body, 0, 100);
                if (strlen($body) > 100) {
                    echo "...";
                }
                echo "\n";
            }
        }

        echo "===================\n";
    }
}
