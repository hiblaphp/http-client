<?php

namespace Hibla\Http\Testing\Interfaces;

interface BuildsCookieMocksInterface
{
    /**
     * Configure the mock to set cookies via Set-Cookie headers.
     *
     * @param array<string, array{value: string, path?: string, domain?: string, expires?: int, secure?: bool, httpOnly?: bool, sameSite?: string}> $cookies
     */
    public function setCookies(array $cookies): static;

    /**
     * Set a single cookie via Set-Cookie header.
     */
    public function setCookie(
        string $name,
        string $value,
        ?string $path = '/',
        ?string $domain = null,
        ?int $expires = null,
        bool $secure = false,
        bool $httpOnly = false,
        ?string $sameSite = null
    ): static;
}
