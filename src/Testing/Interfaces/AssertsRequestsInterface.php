<?php

namespace Hibla\HttpClient\Testing\Interfaces;

use Hibla\HttpClient\Testing\Utilities\RecordedRequest;

interface AssertsRequestsInterface
{
    /**
     * Assert that a specific request was made.
     *
     * @param array<string, mixed> $options
     */
    public function assertRequestMade(string $method, string $url, array $options = []): void;

    /**
     * Assert that no requests were made.
     */
    public function assertNoRequestsMade(): void;

    /**
     * Assert the total number of requests made.
     */
    public function assertRequestCount(int $expected): void;

    /**
     * Get the last recorded request.
     */
    public function getLastRequest(): ?RecordedRequest;

    /**
     * Get a specific request by index.
     */
    public function getRequest(int $index): ?RecordedRequest;

    /**
     * Dump the last request for debugging.
     */
    public function dumpLastRequest(): void;
}
