<?php

namespace Hibla\Http\Testing\Traits\Assertions;

use Hibla\Http\Testing\Exceptions\MockAssertionException;

trait AssertsHeaders
{
    abstract public function getLastRequest();
    abstract public function getRequest(int $index);

    public function assertHeaderSent(string $name, ?string $expectedValue = null, ?int $requestIndex = null): void
    {
        $request = $requestIndex === null
            ? $this->getLastRequest()
            : $this->getRequest($requestIndex);

        if ($request === null) {
            throw new MockAssertionException('No request found at the specified index');
        }

        if (!$request->hasHeader($name)) {
            throw new MockAssertionException("Header '{$name}' was not sent in the request");
        }

        if ($expectedValue !== null) {
            $actualValue = $request->getHeaderLine($name);
            if ($actualValue !== $expectedValue) {
                throw new MockAssertionException(
                    "Header '{$name}' value mismatch. Expected: '{$expectedValue}', Got: '{$actualValue}'"
                );
            }
        }
    }

    public function assertHeaderNotSent(string $name, ?int $requestIndex = null): void
    {
        $request = $requestIndex === null
            ? $this->getLastRequest()
            : $this->getRequest($requestIndex);

        if ($request === null) {
            throw new MockAssertionException('No request found at the specified index');
        }

        if ($request->hasHeader($name)) {
            $value = $request->getHeaderLine($name);
            throw new MockAssertionException(
                "Header '{$name}' was sent in the request with value: '{$value}'"
            );
        }
    }

    public function assertHeadersSent(array $expectedHeaders, ?int $requestIndex = null): void
    {
        foreach ($expectedHeaders as $name => $value) {
            $this->assertHeaderSent($name, $value, $requestIndex);
        }
    }

    public function assertHeaderMatches(string $name, string $pattern, ?int $requestIndex = null): void
    {
        $request = $requestIndex === null
            ? $this->getLastRequest()
            : $this->getRequest($requestIndex);

        if ($request === null) {
            throw new MockAssertionException('No request found at the specified index');
        }

        if (!$request->hasHeader($name)) {
            throw new MockAssertionException("Header '{$name}' was not sent in the request");
        }

        $actualValue = $request->getHeaderLine($name);
        if (!preg_match($pattern, $actualValue)) {
            throw new MockAssertionException(
                "Header '{$name}' does not match pattern '{$pattern}'. Got: '{$actualValue}'"
            );
        }
    }

    public function assertBearerTokenSent(string $expectedToken, ?int $requestIndex = null): void
    {
        $this->assertHeaderSent('authorization', "Bearer {$expectedToken}", $requestIndex);
    }

    public function assertContentType(string $expectedType, ?int $requestIndex = null): void
    {
        $this->assertHeaderSent('content-type', $expectedType, $requestIndex);
    }

    public function assertAcceptHeader(string $expectedType, ?int $requestIndex = null): void
    {
        $this->assertHeaderSent('accept', $expectedType, $requestIndex);
    }

    public function assertUserAgent(string $expectedUserAgent, ?int $requestIndex = null): void
    {
        $this->assertHeaderSent('user-agent', $expectedUserAgent, $requestIndex);
    }
}