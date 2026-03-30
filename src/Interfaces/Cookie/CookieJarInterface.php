<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Cookie;

use Hibla\HttpClient\Cookie;

/**
 * Contract for cookie storage and retrieval.
 *
 * Implementations are responsible for persisting cookies between requests,
 * applying domain and path scoping rules, and expiring stale cookies.
 * The simplest implementation is an in-memory jar; more advanced ones
 * may persist to disk or a database for long-lived sessions.
 */
interface CookieJarInterface
{
    /**
     * Add or replace a cookie in the jar.
     *
     * If a cookie with the same name, domain, and path already exists
     * it must be replaced, not duplicated.
     */
    public function setCookie(Cookie $cookie): void;

    /**
     * Retrieve all cookies that are applicable to the given request context.
     *
     * Implementations must honour domain matching (including sub-domain rules),
     * path prefix matching, and the secure flag when $isSecure is false.
     *
     * @param  string $domain The effective request domain (e.g. 'api.example.com').
     * @param  string $path The effective request path (e.g. '/v1/users').
     * @param  bool $isSecure Whether the request is being made over HTTPS.
     * @return Cookie[]
     */
    public function getCookies(string $domain, string $path, bool $isSecure = false): array;

    /**
     * Return every cookie currently held in the jar, regardless of scope.
     *
     * Primarily useful for serialization, debugging, and testing assertions.
     *
     * @return Cookie[]
     */
    public function getAllCookies(): array;

    /**
     * Remove all cookies whose expiry date is in the past.
     *
     * Should be called periodically to prevent unbounded jar growth
     * in long-running processes.
     */
    public function clearExpired(): void;

    /**
     * Remove all cookies from the jar unconditionally.
     */
    public function clear(): void;

    /**
     * Build the value for a Cookie request header scoped to the given context.
     *
     * Returns an empty string when no cookies match, so callers can
     * check for emptiness before setting the header.
     *
     * @param  string $domain The effective request domain.
     * @param  string $path The effective request path.
     * @param  bool $isSecure Whether the request is being made over HTTPS.
     */
    public function getCookieHeader(string $domain, string $path, bool $isSecure = false): string;
}
