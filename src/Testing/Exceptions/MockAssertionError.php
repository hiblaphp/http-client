<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Exceptions;

class MockAssertionError extends MockException
{
    public function __construct(string $message)
    {
        parent::__construct("Assertion failed: $message");
    }
}
