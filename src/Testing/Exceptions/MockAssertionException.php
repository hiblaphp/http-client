<?php

namespace Hibla\Http\Testing\Exceptions;

/**
 * Thrown when mock assertions fail during testing.
 */
class MockAssertionException extends MockException
{
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null, ?string $url = null)
    {
        parent::__construct("Assertion failed: {$message}", $code, $previous, $url);
    }
}
