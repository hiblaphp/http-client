<?php

declare(strict_types=1);

namespace Tests\Feature;

use Closure;
use Exception;

// 1. Object holding the closure
class MockedRequest
{
    public function __construct(public string $method, public Closure $matcher)
    {
    }
}

// 2. Exception with a static factory
class UnexpectedRequestException extends Exception
{
    public static function noMatchFound(string $url, array $availableMocks): self
    {
        return new self("No mock matched the request for {$url}");
    }
}

// 3. The Test triggering the mismatch
describe('Mock Closure Matching', function () {
    it('crashes the Pest reporter on failure', function () {

        // Define a closure-based expectation
        $mock = new MockedRequest('POST', function ($request) {
            return false;
        });

        // Simulating a failed match in the library executor
        $isMatch = ($mock->matcher)(['data' => 'test']);

        if (! $isMatch) {
            // This call puts $mock (and its closure) into the stack trace arguments.
            // On PHP 8.4 + Windows, this triggers the Whoops crash.
            throw UnexpectedRequestException::noMatchFound('https://api.test.com', [$mock]);
        }
    });
});
