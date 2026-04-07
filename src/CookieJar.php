<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

use Hibla\HttpClient\Interfaces\Cookie\CookieJarInterface;
use Hibla\HttpClient\ValueObjects\Cookie;

/**
 * In-memory cookie jar implementation.
 *
 * Follows the storage model defined in RFC 6265 section 5.3.
 */
final class CookieJar implements CookieJarInterface
{
    /**
     * @var Cookie[]
     */
    private array $cookies = [];

    /**
     * @inheritDoc
     *
     * Per RFC 6265 section 5.3 step 11: if a cookie with the same name, domain,
     * and path already exists in the store, the creation-time of the old cookie
     * is preserved on the replacement (step 11.3), and the old cookie is removed
     * before the new one is inserted (step 11.4).
     */
    public function setCookie(Cookie $cookie): void
    {
        $existing = $this->findExisting($cookie);

        if ($existing !== null) {
            // RFC 6265 section 5.3 step 11.3 — preserve the original creation-time.
            $cookie = new Cookie(
                name: $cookie->getName(),
                value: $cookie->getValue(),
                expires: $cookie->getExpires(),
                domain: $cookie->getDomain(),
                path: $cookie->getPath(),
                secure: $cookie->isSecure(),
                httpOnly: $cookie->isHttpOnly(),
                maxAge: $cookie->getMaxAge(),
                sameSite: $cookie->getSameSite(),
                hostOnly: $cookie->isHostOnly(),
                persistent: $cookie->isPersistent(),
                createdAt: $existing->getCreatedAt(),
            );

            // RFC 6265 section 5.3 step 11.4 — remove the old cookie first.
            $this->removeExisting($cookie);
        }

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
     *
     * Per RFC 6265 section 5.3: the user agent MUST evict all expired cookies
     * from the cookie store at any time an expired cookie exists in the store.
     */
    public function clearExpired(): void
    {
        $this->cookies = array_values(array_filter($this->cookies, function (Cookie $cookie) {
            return ! $cookie->isExpired();
        }));
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
     * The optional $originHost is passed through to Cookie::fromSetCookieHeader so
     * that cookies without a Domain attribute are correctly stored as host-only
     * per RFC 6265 section 5.3 step 6.
     *
     * @param string[] $setCookieHeaders
     * @param string|null $originHost  The host that sent the Set-Cookie headers.
     */
    public static function fromSetCookieHeaders(array $setCookieHeaders, ?string $originHost = null): self
    {
        $jar = new self();

        foreach ($setCookieHeaders as $header) {
            $cookie = Cookie::fromSetCookieHeader($header, $originHost);
            if ($cookie !== null) {
                $jar->setCookie($cookie);
            }
        }

        return $jar;
    }

    /**
     * Find an existing cookie in the store with the same name, domain, and path.
     *
     * Per RFC 6265 section 5.3 step 11: the invariant is that at most one such
     * cookie exists in the store at any time.
     */
    private function findExisting(Cookie $cookie): ?Cookie
    {
        foreach ($this->cookies as $existing) {
            if (
                $existing->getName() === $cookie->getName() &&
                $existing->getDomain() === $cookie->getDomain() &&
                $existing->getPath() === $cookie->getPath()
            ) {
                return $existing;
            }
        }

        return null;
    }

    /**
     * Remove a cookie from the store by name, domain, and path.
     */
    private function removeExisting(Cookie $cookie): void
    {
        $this->cookies = array_values(array_filter(
            $this->cookies,
            fn (Cookie $existing) => ! (
                $existing->getName() === $cookie->getName() &&
                $existing->getDomain() === $cookie->getDomain() &&
                $existing->getPath() === $cookie->getPath()
            ),
        ));
    }
}
