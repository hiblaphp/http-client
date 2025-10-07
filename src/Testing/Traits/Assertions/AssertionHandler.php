<?php

namespace Hibla\HttpClient\Testing\Traits\Assertions;

use Hibla\HttpClient\Testing\Exceptions\MockAssertionException;
use PHPUnit\Framework\TestCase;

trait AssertionHandler
{
    /**
     * Fail an assertion - uses PHPUnit if available, otherwise throws exception.
     *
     * @param string $message
     * @return never
     * @throws MockAssertionException
     */
    protected function failAssertion(string $message): void
    {
        // Check if we're in a PHPUnit context
        if (class_exists(TestCase::class)) {
            // Use PHPUnit's assertion
            \PHPUnit\Framework\Assert::fail($message);
        }

        throw new MockAssertionException($message);
    }
}