<?php

namespace Hibla\HttpClient\Testing\Interfaces;

interface BuildsRequestExpectationsInterface
{
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
