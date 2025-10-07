<?php

namespace Hibla\HttpClient\Testing\Traits\Assertions;

trait AssertsCookies
{
    use AssertionHandler;

    abstract protected function getCookieManager();

    abstract protected function getRequestRecorder();

    /**
     * Assert that a specific cookie was sent in the request.
     */
    public function assertCookieSent(string $name): void
    {
        $this->registerAssertion();

        $history = $this->getRequestRecorder()->getRequestHistory();
        if ($history === []) {
            $this->failAssertion('No requests have been made');
        }

        $lastRequest = end($history);
        if ($lastRequest === false) {
            $this->failAssertion('No requests have been made');
        }

        $this->getCookieManager()->assertCookieSent($name, $lastRequest->options);
    }

    /**
     * Assert that a specific cookie exists in the request.
     */
    public function assertCookieExists(string $name): void
    {
        $this->registerAssertion();
        $this->getCookieManager()->assertCookieExists($name);
    }

    /**
     * Assert that a specific cookie has a specific value.
     */
    public function assertCookieValue(string $name, string $expectedValue): void
    {
        $this->registerAssertion();
        $this->getCookieManager()->assertCookieValue($name, $expectedValue);
    }
}