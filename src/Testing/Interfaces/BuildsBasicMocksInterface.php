<?php

namespace Hibla\Http\Testing\Interfaces;

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
     * Set the response body as JSON.
     * 
     * @param array<string, mixed> $data
     */
    public function respondJson(array $data): static;

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