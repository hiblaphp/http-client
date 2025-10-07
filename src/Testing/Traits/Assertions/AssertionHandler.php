<?php

namespace Hibla\HttpClient\Testing\Traits\Assertions;

use Hibla\HttpClient\Testing\Exceptions\MockAssertionException;
use PHPUnit\Framework\Assert;

trait AssertionHandler
{
    /**
     * Register this as an assertion with PHPUnit.
     */
    protected function registerAssertion(): void
    {
        if (class_exists(Assert::class)) {
            Assert::assertTrue(true);
        }
    }

    /**
     * Fail an assertion - uses PHPUnit if available, otherwise throws exception.
     *
     * @param string $message
     * @return never
     * @throws MockAssertionException
     */
    protected function failAssertion(string $message): void
    {
        if (class_exists(Assert::class)) {
            Assert::fail($message);
        }

        throw new MockAssertionException($message);
    }
}
