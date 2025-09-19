<?php

namespace Hibla\Http\Testing\Exceptions;

use Exception;

class MockAssertionException extends Exception
{
    public function __construct(string $message)
    {
        parent::__construct("Assertion failed: {$message}");
    }
}
