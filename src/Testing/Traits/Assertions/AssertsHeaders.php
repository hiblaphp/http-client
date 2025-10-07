<?php

namespace Hibla\HttpClient\Testing\Traits\Assertions;

trait AssertsHeaders
{
    use AssertionHandler;

    abstract public function getLastRequest();

    abstract public function getRequest(int $index);

    /**
     * Assert that a specific header was sent in the request.
     */
    public function assertHeaderSent(string $name, ?string $expectedValue = null, ?int $requestIndex = null): void
    {
        $request = $requestIndex === null
            ? $this->getLastRequest()
            : $this->getRequest($requestIndex);

        if ($request === null) {
            $this->failAssertion('No request found at the specified index');
        }

        if (! $request->hasHeader($name)) {
            $this->failAssertion("Header '{$name}' was not sent in the request");
        }

        if ($expectedValue !== null) {
            $actualValue = $request->getHeaderLine($name);
            if ($actualValue !== $expectedValue) {
                $this->failAssertion(
                    "Header '{$name}' value mismatch. Expected: '{$expectedValue}', Got: '{$actualValue}'"
                );
            }
        }
    }

    /**
     * Assert that a specific header was not sent in the request.
     */
    public function assertHeaderNotSent(string $name, ?int $requestIndex = null): void
    {
        $request = $requestIndex === null
            ? $this->getLastRequest()
            : $this->getRequest($requestIndex);

        if ($request === null) {
            $this->failAssertion('No request found at the specified index');
        }

        if ($request->hasHeader($name)) {
            $value = $request->getHeaderLine($name);

            $this->failAssertion(
                "Header '{$name}' was sent in the request with value: '{$value}'"
            );
        }
    }

    /**
     * Assert that multiple headers were sent in the request.
     *
     * @param array<string, string> $expectedHeaders
     */
    public function assertHeadersSent(array $expectedHeaders, ?int $requestIndex = null): void
    {
        foreach ($expectedHeaders as $name => $value) {
            $this->assertHeaderSent($name, $value, $requestIndex);
        }
    }

    /**
     * Assert that a specific header value matches a given pattern.
     */
    public function assertHeaderMatches(string $name, string $pattern, ?int $requestIndex = null): void
    {
        $request = $requestIndex === null
            ? $this->getLastRequest()
            : $this->getRequest($requestIndex);

        if ($request === null) {
            $this->failAssertion('No request found at the specified index');
        }

        if (! $request->hasHeader($name)) {
            $this->failAssertion("Header '{$name}' was not sent in the request");
        }

        $actualValue = $request->getHeaderLine($name);
        if ($actualValue === null || preg_match($pattern, $actualValue) !== 1) {
            $this->failAssertion(
                "Header '{$name}' does not match pattern '{$pattern}'. Got: '{$actualValue}'"
            );
        }
    }

    /**
     * Assert that a Bearer token was sent in the request.
     */
    public function assertBearerTokenSent(string $expectedToken, ?int $requestIndex = null): void
    {
        $this->assertHeaderSent('authorization', "Bearer {$expectedToken}", $requestIndex);
    }

    /**
     * Assert that the Content-Type header matches the expected value.
     */
    public function assertContentType(string $expectedType, ?int $requestIndex = null): void
    {
        $this->assertHeaderSent('content-type', $expectedType, $requestIndex);
    }

    /**
     * Assert that the Accept header matches the expected value.
     */
    public function assertAcceptHeader(string $expectedType, ?int $requestIndex = null): void
    {
        $this->assertHeaderSent('accept', $expectedType, $requestIndex);
    }

    /**
     * Assert that the User-Agent header matches the expected value.
     */
    public function assertUserAgent(string $expectedUserAgent, ?int $requestIndex = null): void
    {
        $this->assertHeaderSent('user-agent', $expectedUserAgent, $requestIndex);
    }
}