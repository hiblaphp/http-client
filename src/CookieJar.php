<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

use Hibla\HttpClient\Interfaces\Cookie\CookieJarInterface;

/**
 * In-memory cookie jar implementation.
 */
class CookieJar implements CookieJarInterface
{
    /** @var Cookie[] */
    protected array $cookies = [];

    /**
     * @inheritDoc
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
     * @inheritDoc
     */
    public function getCookies(string $domain, string $path, bool $isSecure = false): array
    {
        $this->clearExpired();

        return array_filter($this->cookies, function (Cookie $cookie) use ($domain, $path, $isSecure) {
            return $cookie->matches($domain, $path, $isSecure);
        });
    }

    /**
     * @inheritDoc
     */
    public function getAllCookies(): array
    {
        return $this->cookies;
    }

    /**
     * @inheritDoc
     */
    public function clearExpired(): void
    {
        $this->cookies = array_filter($this->cookies, function (Cookie $cookie) {
            return ! $cookie->isExpired();
        });
    }

    /**
     * @inheritDoc
     */
    public function clear(): void
    {
        $this->cookies = [];
    }

    /**
     * @inheritDoc
     */
    public function getCookieHeader(string $domain, string $path, bool $isSecure = false): string
    {
        $matchingCookies = $this->getCookies($domain, $path, $isSecure);

        if (\count($matchingCookies) === 0) {
            return '';
        }

        return implode('; ', array_map(function (Cookie $cookie) {
            return $cookie->toCookieHeader();
        }, $matchingCookies));
    }

    /**
     * Create a cookie jar pre-populated from an array of Set-Cookie header values.
     *
     * @param  string[]  $setCookieHeaders
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