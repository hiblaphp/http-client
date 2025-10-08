<?php

namespace Hibla\HttpClient\Testing\Traits\Assertions;

use Hibla\HttpClient\Testing\Exceptions\MockAssertionError;
use PHPUnit\Framework\Assert;

trait AssertionHandler
{
    /**
     * Register this as an assertion with PHPUnit.
     */
    protected function registerAssertion(): void
    {
       
        if (class_exists(Assert::class)) {
             /*@phpstan-ignore-next-line*/
            Assert::assertTrue(true);
        }
    }

    /**
     * Fail an assertion - uses PHPUnit if available, otherwise throws exception.
     *
     * @param string $message
     * @return never
     * @throws MockAssertionError
     */
    protected function failAssertion(string $message): void
    {
        if (class_exists(Assert::class)) {
            Assert::fail($message);
        }

        throw new MockAssertionError($message);
    }
}
