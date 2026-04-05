<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Interfaces;

interface BuildsBasicMocksInterface
{
    /**
     * Set the URL pattern to match.
     */
    public function url(string $pattern): static;

    /**
     * Set the HTTP status code for the response.
     */
    public function respondWithStatus(int $status = 200): static;

    /**
     * Shorthand for respondWithStatus().
     */
    public function status(int $status): static;

    /**
     * Set the response body as a string.
     */
    public function respondWith(string $body): static;

    /**
     * Configure the latency injected between each 8KB chunk of data during a transfer.
     *
     * Useful for simulating slow connections, testing timeouts, or progress bars.
     *
     * @param float $seconds Seconds to wait between each chunk.
     * @param float $jitter Random variation (0.0 to 1.0) to apply to the latency.
     */
    public function dataStreamTransferLatency(float $seconds, float $jitter = 0): static;

    /**
     * Set the response body as JSON.
     *
     * @param array<string, mixed> $data
     */
    public function respondJson(array $data): static;

    /**
     * Set the response body as XML.
     *
     * @param string|\SimpleXMLElement $xml
     */
    public function respondXml(string|\SimpleXMLElement $xml): static;

    /**
     * Add a delay before responding.
     */
    public function delay(float $seconds): static;

    /**
     * Set a random delay range for realistic network simulation.
     */
    public function randomDelay(float $minSeconds, float $maxSeconds): static;

    /**
     * Create a persistent mock with random delays for each request.
     */
    public function randomPersistentDelay(float $minSeconds, float $maxSeconds): static;

    /**
     * Simulate a slow response.
     */
    public function slowResponse(float $delaySeconds): static;

    /**
     * Make this mock persistent (reusable for multiple requests).
     */
    public function persistent(): static;
}
