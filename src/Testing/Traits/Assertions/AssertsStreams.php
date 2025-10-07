<?php

namespace Hibla\HttpClient\Testing\Traits\Assertions;

use Hibla\HttpClient\Testing\Exceptions\MockAssertionException;
use Hibla\HttpClient\Testing\Utilities\RecordedRequest;

trait AssertsStreams
{
    use AssertionHandler;

    /**
     * @return array<int, RecordedRequest>
     */
    abstract public function getRequestHistory(): array;

    /**
     * Assert that a streaming request was made.
     *
     * @param string $url The URL that was streamed
     */
    public function assertStreamMade(string $url): void
    {
        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();

            if ($request->getUrl() === $url && isset($options['stream']) && $options['stream'] === true) {
                return;
            }
        }

        $this->failAssertion(
            "Expected stream not found for URL: {$url}"
        );
    }

    /**
     * Assert that a streaming request was made with a chunk callback.
     *
     * @param string $url The URL that was streamed
     */
    public function assertStreamWithCallback(string $url): void
    {
        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();

            if (
                $request->getUrl() === $url &&
                isset($options['stream']) &&
                $options['stream'] === true &&
                isset($options['on_chunk'])
            ) {
                return;
            }
        }

        $this->failAssertion(
            "Expected stream with callback not found for URL: {$url}"
        );
    }

    /**
     * Assert that a streaming request was made with specific headers.
     *
     * @param string $url The URL that was streamed
     * @param array<string, string> $expectedHeaders Expected headers
     */
    public function assertStreamWithHeaders(string $url, array $expectedHeaders): void
    {
        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();

            if ($request->getUrl() === $url && isset($options['stream']) && $options['stream'] === true) {
                $matches = true;

                foreach ($expectedHeaders as $name => $value) {
                    $headerValue = $request->getHeader($name);

                    if ($headerValue === null || $headerValue !== $value) {
                        $matches = false;
                        break;
                    }
                }

                if ($matches) {
                    return;
                }
            }
        }

        $this->failAssertion(
            "Expected stream with headers not found for URL: {$url}"
        );
    }

    /**
     * Assert that a streaming request was made using a specific HTTP method.
     *
     * @param string $url The URL that was streamed
     * @param string $method Expected HTTP method
     */
    public function assertStreamWithMethod(string $url, string $method): void
    {
        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();

            if (
                $request->getUrl() === $url &&
                isset($options['stream']) &&
                $options['stream'] === true &&
                strtoupper($request->getMethod()) === strtoupper($method)
            ) {
                return;
            }
        }

        $this->failAssertion(
            "Expected stream with method {$method} not found for URL: {$url}"
        );
    }

    /**
     * Assert that no streaming requests were made.
     *
     */
    public function assertNoStreamsMade(): void
    {
        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();

            if (isset($options['stream']) && $options['stream'] === true) {
                throw new MockAssertionException(
                    'Expected no streams, but at least one was made to: ' .
                        $request->getUrl()
                );
            }
        }
    }

    /**
     * Assert a specific number of streaming requests were made.
     *
     * @param int $expected Expected number of streams
     */
    public function assertStreamCount(int $expected): void
    {
        $actual = 0;

        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();

            if (isset($options['stream']) && $options['stream'] === true) {
                $actual++;
            }
        }

        if ($actual !== $expected) {
            $this->failAssertion(
                "Expected {$expected} streams, but {$actual} were made"
            );
        }
    }

    /**
     * Get all streaming requests from history.
     *
     * @return array<int, RecordedRequest>
     */
    public function getStreamRequests(): array
    {
        $streams = [];

        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();

            if (isset($options['stream']) && $options['stream'] === true) {
                $streams[] = $request;
            }
        }

        return $streams;
    }

    /**
     * Get the last streaming request.
     *
     * @return RecordedRequest|null
     */
    public function getLastStream(): ?RecordedRequest
    {
        $streams = $this->getStreamRequests();

        if ($streams === []) {
            return null;
        }

        return $streams[count($streams) - 1];
    }

    /**
     * Get the first streaming request.
     *
     * @return RecordedRequest|null
     */
    public function getFirstStream(): ?RecordedRequest
    {
        $streams = $this->getStreamRequests();

        if ($streams === []) {
            return null;
        }

        return $streams[0];
    }

    /**
     * Check if a stream request has a callback.
     *
     * @param RecordedRequest $request The request to check
     * @return bool
     */
    public function streamHasCallback(RecordedRequest $request): bool
    {
        $options = $request->getOptions();
        return isset($options['on_chunk']);
    }

    /**
     * Dump information about all streams for debugging.
     *
     * @return void
     */
    public function dumpStreams(): void
    {
        $streams = $this->getStreamRequests();

        if ($streams === []) {
            echo "No streams recorded\n";
            return;
        }

        echo "=== Streams (" . count($streams) . ") ===\n";

        foreach ($streams as $index => $request) {
            $options = $request->getOptions();

            echo "\n[{$index}] {$request->getMethod()} {$request->getUrl()}\n";
            echo "    Has callback: " . (isset($options['on_chunk']) ? "Yes" : "No") . "\n";

            $headers = $request->getHeaders();
            if ($headers !== []) {
                echo "    Headers:\n";
                foreach ($headers as $name => $value) {
                    $displayValue = is_array($value) ? implode(', ', $value) : $value;
                    echo "      {$name}: {$displayValue}\n";
                }
            }
        }

        echo "===================\n";
    }

    /**
     * Dump detailed information about the last stream.
     *
     * @return void
     */
    public function dumpLastStream(): void
    {
        $stream = $this->getLastStream();

        if ($stream === null) {
            echo "No streams recorded\n";
            return;
        }

        $options = $stream->getOptions();

        echo "=== Last Stream ===\n";
        echo "Method: {$stream->getMethod()}\n";
        echo "URL: {$stream->getUrl()}\n";
        echo "Has callback: " . (isset($options['on_chunk']) ? "Yes" : "No") . "\n";

        echo "\nHeaders:\n";
        foreach ($stream->getHeaders() as $name => $value) {
            $displayValue = is_array($value) ? implode(', ', $value) : $value;
            echo "  {$name}: {$displayValue}\n";
        }

        echo "===================\n";
    }
}
