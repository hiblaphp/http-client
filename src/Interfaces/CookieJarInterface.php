<?php

namespace Hibla\Http\Interfaces;

use Hibla\Http\Cookie;

/**
 * Interface for cookie storage and management.
 */
interface CookieJarInterface
{
    /**
     * Add a cookie to the jar.
     */
    public function setCookie(Cookie $cookie): void;

    /**
     * Get all cookies that match the given criteria.
     *
     * @param  string  $domain  The request domain
     * @param  string  $path  The request path
     * @param  bool  $isSecure  Whether the request is over HTTPS
     * @return Cookie[] Array of matching cookies
     */
    public function getCookies(string $domain, string $path, bool $isSecure = false): array;

    /**
     * Get all cookies in the jar.
     *
     * @return Cookie[] Array of all cookies
     */
    public function getAllCookies(): array;

    /**
     * Remove expired cookies from the jar.
     */
    public function clearExpired(): void;

    /**
     * Clear all cookies from the jar.
     */
    public function clear(): void;

    /**
     * Get cookies formatted for Cookie header.
     */
    public function getCookieHeader(string $domain, string $path, bool $isSecure = false): string;
}
