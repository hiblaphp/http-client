<?php

namespace Hibla\Http;

use Hibla\Http\Interfaces\CookieJarInterface;

/**
 * In-memory cookie jar implementation.
 */
class CookieJar implements CookieJarInterface
{
    /** @var Cookie[] */
    protected array $cookies = [];

    /**
     * Sets a cookie in the jar, replacing any existing cookie with the same name, domain, and path.
     */
    public function setCookie(Cookie $cookie): void
    {
        $this->cookies = array_filter($this->cookies, function (Cookie $existingCookie) use ($cookie) {
            return ! (
                $existingCookie->getName() === $cookie->getName() &&
                $existingCookie->getDomain() === $cookie->getDomain() &&
                $existingCookie->getPath() === $cookie->getPath()
            );
        });

        $this->cookies[] = $cookie;
    }

    /**
     * Gets all cookies that match the given domain and path.
     */
    public function getCookies(string $domain, string $path, bool $isSecure = false): array
    {
        $this->clearExpired();

        return array_filter($this->cookies, function (Cookie $cookie) use ($domain, $path, $isSecure) {
            return $cookie->matches($domain, $path, $isSecure);
        });
    }

    /**
     * Gets all cookies in the jar.
     */
    public function getAllCookies(): array
    {
        return $this->cookies;
    }

    /**
     * Removes expired cookies from the jar.
     */
    public function clearExpired(): void
    {
        $this->cookies = array_filter($this->cookies, function (Cookie $cookie) {
            return ! $cookie->isExpired();
        });
    }

    /**
     * Clears all cookies from the jar.
     */
    public function clear(): void
    {
        $this->cookies = [];
    }

    /**
     * Gets the Cookie header value for matching cookies.
     */
    public function getCookieHeader(string $domain, string $path, bool $isSecure = false): string
    {
        $matchingCookies = $this->getCookies($domain, $path, $isSecure);

        if (count($matchingCookies) === 0) {
            return '';
        }

        return implode('; ', array_map(function (Cookie $cookie) {
            return $cookie->toCookieHeader();
        }, $matchingCookies));
    }

    /**
     * Creates a cookie jar from an array of Set-Cookie headers.
     *
     * @param  string[]  $setCookieHeaders  Array of Set-Cookie header values
     */
    public static function fromSetCookieHeaders(array $setCookieHeaders): self
    {
        $jar = new self();

        foreach ($setCookieHeaders as $header) {
            $cookie = Cookie::fromSetCookieHeader($header);
            if ($cookie !== null) {
                $jar->setCookie($cookie);
            }
        }

        return $jar;
    }
}
