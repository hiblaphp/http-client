<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Builder;

use Hibla\HttpClient\Interfaces\Cookie\CookieJarInterface;

/**
 * Fluent interface for cookie management.
 *
 * ## State Management Note
 * While the HttpClient itself is immutable (every method returns a new clone),
 * the underlying CookieJar is a **shared mutable object**.
 *
 * If multiple client instances share the same CookieJar instance, modifications
 * to that jar (via clearCookies, cookieWithAttributes, or receiving a 
 * Set-Cookie response) will affect all client instances sharing that jar.
 */
interface ConfiguresCookiesInterface
{
    /**
     * Add a single cookie to the Cookie request header.
     *
     * The value is percent-encoded before being written to the header.
     * Calling this method multiple times appends each cookie with '; '.
     * 
     * Note: This modifies the request-level state of the returned instance 
     * only and does not affect the active CookieJar.
     */
    public function withCookie(string $name, string $value): static;

    /**
     * Add multiple cookies to the Cookie request header.
     *
     * Equivalent to calling withCookie() for each entry.
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
     * 
     * Once set, this specific jar instance is shared across all clones 
     * of this client until replaced.
     */
    public function useCookieJar(CookieJarInterface $cookieJar): static;

    /**
     * Clear all cookies from the currently active jar.
     *
     * This is a **mutable operation** on the underlying jar object.
     * All client instances sharing this jar will see their cookies removed.
     * 
     * No-op when no jar is configured. Does not remove one-shot cookies
     * set via withCookie() / withCookies().
     */
    public function clearCookies(): static;

    /**
     * Add a cookie with full attribute control to the active jar.
     *
     * Initialises an in-memory jar if one is not already configured.
     * 
     * This is a **mutable operation** on the underlying jar object.
     * All client instances sharing this jar will immediately have access 
     * to this cookie.
     *
     * Recognised $attributes keys: domain, path, expires, maxAge,
     * secure, httpOnly, sameSite.
     *
     * @param array<string, mixed> $attributes
     */
    public function cookieWithAttributes(string $name, string $value, array $attributes = []): static;

    /**
     * Return the currently active cookie jar, or null if none is configured.
     * 
     * Since the jar is mutable, you can retrieve it and manipulate it 
     * directly outside of the fluent builder if needed.
     */
    public function getCookieJar(): ?CookieJarInterface;
}