<?php

namespace Hibla\HttpClient\Testing\Interfaces;

interface AssertsHeadersInterface
{
    public function assertHeaderSent(string $name, ?string $expectedValue = null, ?int $requestIndex = null): void;

    public function assertHeaderNotSent(string $name, ?int $requestIndex = null): void;

    /**
     * @param array<string, string> $expectedHeaders
     */
    public function assertHeadersSent(array $expectedHeaders, ?int $requestIndex = null): void;

    public function assertHeaderMatches(string $name, string $pattern, ?int $requestIndex = null): void;

    public function assertBearerTokenSent(string $expectedToken, ?int $requestIndex = null): void;

    public function assertContentType(string $expectedType, ?int $requestIndex = null): void;

    public function assertAcceptHeader(string $expectedType, ?int $requestIndex = null): void;

    public function assertUserAgent(string $expectedUserAgent, ?int $requestIndex = null): void;
}
