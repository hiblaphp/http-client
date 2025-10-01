<?php

namespace Hibla\Http\Testing\Traits\Assertions;

use Hibla\Http\Testing\Exceptions\MockAssertionException;

trait AssertsCookies
{
    abstract protected function getCookieManager();
    abstract protected function getRequestRecorder();

    public function assertCookieSent(string $name): void
    {
        $history = $this->getRequestRecorder()->getRequestHistory();
        if (empty($history)) {
            throw new MockAssertionException('No requests have been made');
        }

        $lastRequest = end($history);
        $this->getCookieManager()->assertCookieSent($name, $lastRequest->options);
    }

    public function assertCookieExists(string $name): void
    {
        $this->getCookieManager()->assertCookieExists($name);
    }

    public function assertCookieValue(string $name, string $expectedValue): void
    {
        $this->getCookieManager()->assertCookieValue($name, $expectedValue);
    }
}