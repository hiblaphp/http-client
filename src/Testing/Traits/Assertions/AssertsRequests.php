<?php
namespace Hibla\Http\Testing\Traits\Assertions;

use Hibla\Http\Testing\Exceptions\MockAssertionException;
use Hibla\Http\Testing\Utilities\RecordedRequest;

trait AssertsRequests
{
    /**
     * @return array<int, RecordedRequest>
     */
    abstract public function getRequestHistory(): array;
    abstract protected function getRequestRecorder();
    abstract protected function getRequestMatcher();

    /**
     * Assert that a request was made with the given method, URL, and options.
     *
     * @param array<string, mixed> $options
     */
    public function assertRequestMade(string $method, string $url, array $options = []): void
    {
        foreach ($this->getRequestHistory() as $request) {
            if ($this->getRequestMatcher()->matchesRequest($request, $method, $url, $options)) {
                return;
            }
        }

        throw new MockAssertionException("Expected request not found: {$method} {$url}");
    }

    /**
     * Assert that no requests were made.
     */
    public function assertNoRequestsMade(): void
    {
        $history = $this->getRequestHistory();
        if ($history !== []) {
            throw new MockAssertionException('Expected no requests, but ' . count($history) . ' were made');
        }
    }

    /**
     * Assert that a specific number of requests were made.
     */
    public function assertRequestCount(int $expected): void
    {
        $actual = count($this->getRequestHistory());
        if ($actual !== $expected) {
            throw new MockAssertionException("Expected {$expected} requests, but {$actual} were made");
        }
    }

    /**
     * Get the last recorded request.
     */
    public function getLastRequest(): ?RecordedRequest
    {
        return $this->getRequestRecorder()->getLastRequest();
    }

    /**
     * Get a specific request by index.
     */
    public function getRequest(int $index): ?RecordedRequest
    {
        return $this->getRequestRecorder()->getRequest($index);
    }

    /**
     * Dump the last recorded request for debugging.
     */
    public function dumpLastRequest(): void
    {
        $request = $this->getLastRequest();
        if ($request === null) {
            echo "No requests recorded\n";
            return;
        }

        echo "=== Last Request ===\n";
        echo "Method: {$request->getMethod()}\n";
        echo "URL: {$request->getUrl()}\n";
        echo "\nHeaders:\n";
        foreach ($request->getHeaders() as $name => $value) {
            $displayValue = is_array($value) ? implode(', ', $value) : $value;
            echo "  {$name}: {$displayValue}\n";
        }

        $body = $request->getBody();
        if ($body !== null && $body !== '') {
            echo "\nBody:\n";
            echo $body . "\n";
        }

        $json = $request->getJson();
        if ($json !== null) {
            echo "\nParsed JSON:\n";
            print_r($json);
        }
        echo "===================\n";
    }
}