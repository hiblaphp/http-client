<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Interfaces;

use Hibla\HttpClient\Testing\Utilities\RecordedRequest;

interface BuildsRequestExpectationsInterface
{
    /**
     * Use a custom closure to determine if a request matches this mock.
     *
     * The callback receives a RecordedRequest instance and must return a boolean.
     *
     * @param callable(RecordedRequest): bool $callback
     */
    public function expect(callable $callback): static;

    /**
     * Expect a specific header in the request.
     */
    public function expectHeader(string $name, string $value): static;

    /**
     * Expect multiple headers in the request.
     *
     * @param array<string, string> $headers
     */
    public function expectHeaders(array $headers): static;

    /**
     * Expect a specific body pattern in the request.
     */
    public function expectBody(string $pattern): static;

    /**
     * Expect specific JSON data in the request body.
     *
     * @param array<string, mixed> $data
     */
    public function expectJson(array $data): static;

    /**
     * Expect specific cookies to be present in the request.
     *
     * @param array<string, string> $expectedCookies
     */
    public function expectCookies(array $expectedCookies): static;
}
