<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Builder;

use Hibla\HttpClient\Interfaces\Cookie\CookieJarInterface;

/**
 * Fluent interface for cookie management.
 *
 * Two strategies are available and can be combined:
 *
 *   1. Manual cookies — added directly to the Cookie request header
 *      via withCookie() / withCookies(). These are one-shot values
 *      with no domain or expiry awareness.
 *
 *   2. Cookie jar — an active jar instance that automatically stores
 *      Set-Cookie response headers and forwards matching cookies on
 *      subsequent requests within the same session.
 *
 * When both are configured the jar takes precedence for overlapping
 * cookie names on a given domain.
 */
interface ConfiguresCookiesInterface
{
    /**
     * Add a single cookie to the Cookie request header.
     *
     * The value is percent-encoded before being written to the header.
     * Calling this method multiple times appends each cookie with '; '.
     */
    public function withCookie(string $name, string $value): static;

    /**
     * Add multiple cookies to the Cookie request header.
     *
     * Equivalent to calling withCookie() for each entry.
     *
     * @param  array<string, string>  $cookies
     */
    public function withCookies(array $cookies): static;

    /**
     * Enable automatic cookie management with a fresh in-memory jar.
     *
     * Cookies received in Set-Cookie response headers are stored and
     * forwarded automatically on subsequent requests in this session.
     */
    public function withCookieJar(): static;

    /**
     * Use an existing cookie jar instance.
     *
     * Useful for sharing a jar across multiple requests or providing
     * a custom implementation (e.g. persistent, encrypted).
     */
    public function useCookieJar(CookieJarInterface $cookieJar): static;

    /**
     * Clear all cookies from the currently active jar.
     *
     * No-op when no jar is configured. Does not remove cookies
     * set via withCookie() / withCookies().
     */
    public function clearCookies(): static;

    /**
     * Add a cookie with full attribute control to the active jar.
     *
     * Initialises an in-memory jar if one is not already configured.
     * Recognised $attributes keys: domain, path, expires, maxAge,
     * secure, httpOnly, sameSite.
     *
     * @param array<string, mixed> $attributes
     */
    public function cookieWithAttributes(string $name, string $value, array $attributes = []): static;

    /**
     * Return the currently active cookie jar, or null if none is configured.
     */
    public function getCookieJar(): ?CookieJarInterface;
}
