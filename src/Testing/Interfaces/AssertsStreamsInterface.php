<?php

namespace Hibla\Http\Testing\Interfaces;

use Hibla\Http\Testing\Exceptions\MockAssertionException;
use Hibla\Http\Testing\Utilities\RecordedRequest;

interface AssertsStreamsInterface
{
    /**
     * Assert that a streaming request was made.
     *
     * @param string $url The URL that was streamed
     * @throws MockAssertionException
     */
    public function assertStreamMade(string $url): void;

    /**
     * Assert that a streaming request was made with a chunk callback.
     *
     * @param string $url The URL that was streamed
     * @throws MockAssertionException
     */
    public function assertStreamWithCallback(string $url): void;

    /**
     * Assert that a streaming request was made with specific headers.
     *
     * @param string $url The URL that was streamed
     * @param array<string, string> $expectedHeaders Expected headers
     * @throws MockAssertionException
     */
    public function assertStreamWithHeaders(string $url, array $expectedHeaders): void;

    /**
     * Assert that a streaming request was made using a specific HTTP method.
     *
     * @param string $url The URL that was streamed
     * @param string $method Expected HTTP method
     * @throws MockAssertionException
     */
    public function assertStreamWithMethod(string $url, string $method): void;

    /**
     * Assert that no streaming requests were made.
     *
     * @throws MockAssertionException
     */
    public function assertNoStreamsMade(): void;

    /**
     * Assert a specific number of streaming requests were made.
     *
     * @param int $expected Expected number of streams
     * @throws MockAssertionException
     */
    public function assertStreamCount(int $expected): void;

    /**
     * Get all streaming requests from history.
     *
     * @return array<int, RecordedRequest>
     */
    public function getStreamRequests(): array;

    /**
     * Get the last streaming request.
     *
     * @return RecordedRequest|null
     */
    public function getLastStream(): ?RecordedRequest;

    /**
     * Get the first streaming request.
     *
     * @return RecordedRequest|null
     */
    public function getFirstStream(): ?RecordedRequest;

    /**
     * Check if a stream request has a callback.
     *
     * @param RecordedRequest $request The request to check
     * @return bool
     */
    public function streamHasCallback(RecordedRequest $request): bool;

    /**
     * Dump information about all streams for debugging.
     *
     * @return void
     */
    public function dumpStreams(): void;

    /**
     * Dump detailed information about the last stream.
     *
     * @return void
     */
    public function dumpLastStream(): void;
}