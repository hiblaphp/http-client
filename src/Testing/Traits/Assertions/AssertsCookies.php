<?php
namespace Hibla\Http\Testing\Traits\Assertions;

use Hibla\Http\Testing\Exceptions\MockAssertionException;

trait AssertsCookies
{
    abstract protected function getCookieManager();
    abstract protected function getRequestRecorder();

    /**
     * Assert that a specific cookie was sent in the request.
     */
    public function assertCookieSent(string $name): void
    {
        $history = $this->getRequestRecorder()->getRequestHistory();
        if ($history === []) {
            throw new MockAssertionException('No requests have been made');
        }

        $lastRequest = end($history);
        if ($lastRequest === false) {
            throw new MockAssertionException('No requests have been made');
        }
        
        $this->getCookieManager()->assertCookieSent($name, $lastRequest->options);
    }
    
    /**
     * Assert that a specific cookie exists in the request.
     */
    public function assertCookieExists(string $name): void
    {
        $this->getCookieManager()->assertCookieExists($name);
    }
    
    /**
     * Assert that a specific cookie has a specific value.
     */
    public function assertCookieValue(string $name, string $expectedValue): void
    {
        $this->getCookieManager()->assertCookieValue($name, $expectedValue);
    }
}