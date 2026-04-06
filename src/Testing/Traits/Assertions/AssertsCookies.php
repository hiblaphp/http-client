<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Traits\Assertions;

use Hibla\HttpClient\Testing\Exceptions\MockAssertionException;

trait AssertsCookies
{
    use AssertionHandler;

    abstract protected function getCookieManager();

    abstract protected function getRequestRecorder();

    abstract protected function getRequestMatcher();

    /**
     * Assert that a specific cookie was sent in the last request.
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

        try {
            $this->getCookieManager()->assertCookieSent($name, $lastRequest->options);
        } catch (MockAssertionException $e) {
            $this->failAssertion($e->getMessage());
        }
    }

    /**
     * Assert that a specific cookie was NOT sent in the last request.
     */
    public function assertCookieNotSent(string $name): void
    {
        $this->registerAssertion();

        $history = $this->getRequestRecorder()->getRequestHistory();
        if ($history === []) {
            return;
        }

        $lastRequest = end($history);
        if ($lastRequest !== false) {
            try {
                $this->getCookieManager()->assertCookieNotSent($name, $lastRequest->options);
            } catch (MockAssertionException $e) {
                $this->failAssertion($e->getMessage());
            }
        }
    }

    /**
     * Assert that a cookie was sent to a specific URL pattern.
     */
    public function assertCookieSentToUrl(string $name, string $url): void
    {
        $this->registerAssertion();
        $matchedAnyRequest = false;

        foreach ($this->getRequestRecorder()->getRequestHistory() as $request) {
            if ($this->getRequestMatcher()->matchesRequest($request, '*', $url)) {
                $matchedAnyRequest = true;
                try {
                    $this->getCookieManager()->assertCookieSent($name, $request->options);
                    return; // Found a matching request with the cookie!
                } catch (MockAssertionException $e) {
                    continue; // Check the next matching request
                }
            }
        }

        if (!$matchedAnyRequest) {
            $this->failAssertion("No requests were made to URL: {$url}");
        }

        $this->failAssertion("Cookie '{$name}' was not sent to URL: {$url}");
    }

    /**
     * Assert that a cookie was NOT sent to a specific URL pattern.
     */
    public function assertCookieNotSentToUrl(string $name, string $url): void
    {
        $this->registerAssertion();

        foreach ($this->getRequestRecorder()->getRequestHistory() as $request) {
            if ($this->getRequestMatcher()->matchesRequest($request, '*', $url)) {
                try {
                    $this->getCookieManager()->assertCookieSent($name, $request->options);
                    $this->failAssertion("Cookie '{$name}' was unexpectedly sent to URL: {$url}");
                } catch (MockAssertionException $e) {
                    // Not sent in this request, which is what we want.
                }
            }
        }
    }

    /**
     * Assert that a specific cookie exists in the jar.
     */
    public function assertCookieExists(string $name): void
    {
        $this->registerAssertion();
        try {
            $this->getCookieManager()->assertCookieExists($name);
        } catch (MockAssertionException $e) {
            $this->failAssertion($e->getMessage());
        }
    }

    /**
     * Assert that a specific cookie has a specific value in the jar.
     */
    public function assertCookieValue(string $name, string $expectedValue): void
    {
        $this->registerAssertion();
        try {
            $this->getCookieManager()->assertCookieValue($name, $expectedValue);
        } catch (MockAssertionException $e) {
            $this->failAssertion($e->getMessage());
        }
    }

    /**
     * Assert that a specific cookie in the jar has matching attributes.
     * 
     * @param array<string, mixed> $attributes
     */
    public function assertCookieHasAttributes(string $name, array $attributes): void
    {
        $this->registerAssertion();
        try {
            $this->getCookieManager()->assertCookieHasAttributes($name, $attributes);
        } catch (MockAssertionException $e) {
            $this->failAssertion($e->getMessage());
        }
    }

    /**
     * Assert that a cookie in the jar is expired.
     */
    public function assertCookieExpired(string $name): void
    {
        $this->registerAssertion();
        try {
            $this->getCookieManager()->assertCookieExpired($name);
        } catch (MockAssertionException $e) {
            $this->failAssertion($e->getMessage());
        }
    }

    /**
     * Assert that a cookie in the jar is not expired.
     */
    public function assertCookieNotExpired(string $name): void
    {
        $this->registerAssertion();
        try {
            $this->getCookieManager()->assertCookieNotExpired($name);
        } catch (MockAssertionException $e) {
            $this->failAssertion($e->getMessage());
        }
    }

    /**
     * Assert that a cookie in the jar has the Secure flag set.
     */
    public function assertCookieIsSecure(string $name): void
    {
        $this->registerAssertion();
        try {
            $this->getCookieManager()->assertCookieIsSecure($name);
        } catch (MockAssertionException $e) {
            $this->failAssertion($e->getMessage());
        }
    }

    /**
     * Assert that a cookie in the jar has the HttpOnly flag set.
     */
    public function assertCookieIsHttpOnly(string $name): void
    {
        $this->registerAssertion();
        try {
            $this->getCookieManager()->assertCookieIsHttpOnly($name);
        } catch (MockAssertionException $e) {
            $this->failAssertion($e->getMessage());
        }
    }

    /**
     * Assert that a cookie in the jar is a host-only cookie (no Domain attribute).
     */
    public function assertCookieIsHostOnly(string $name): void
    {
        $this->registerAssertion();
        try {
            $this->getCookieManager()->assertCookieIsHostOnly($name);
        } catch (MockAssertionException $e) {
            $this->failAssertion($e->getMessage());
        }
    }
}