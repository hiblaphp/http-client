<?php

namespace Hibla\Http\Testing\Traits\Assertions;

use Hibla\Http\Testing\Exceptions\MockAssertionException;
use Hibla\Http\Testing\Utilities\RecordedRequest;

trait AssertsRequests
{
    abstract public function getRequestHistory(): array;
    abstract protected function getRequestRecorder();
    abstract protected function getRequestMatcher();

    public function assertRequestMade(string $method, string $url, array $options = []): void
    {
        foreach ($this->getRequestHistory() as $request) {
            if ($this->getRequestMatcher()->matchesRequest($request, $method, $url, $options)) {
                return;
            }
        }

        throw new MockAssertionException("Expected request not found: {$method} {$url}");
    }

    public function assertNoRequestsMade(): void
    {
        $history = $this->getRequestHistory();
        if (!empty($history)) {
            throw new MockAssertionException('Expected no requests, but ' . count($history) . ' were made');
        }
    }

    public function assertRequestCount(int $expected): void
    {
        $actual = count($this->getRequestHistory());
        if ($actual !== $expected) {
            throw new MockAssertionException("Expected {$expected} requests, but {$actual} were made");
        }
    }

    public function getLastRequest(): ?RecordedRequest
    {
        return $this->getRequestRecorder()->getLastRequest();
    }

    public function getRequest(int $index): ?RecordedRequest
    {
        return $this->getRequestRecorder()->getRequest($index);
    }

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

        if ($request->getBody()) {
            echo "\nBody:\n";
            echo $request->getBody() . "\n";
        }

        if ($request->getJson()) {
            echo "\nParsed JSON:\n";
            print_r($request->getJson());
        }
        echo "===================\n";
    }
}