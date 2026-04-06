<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Interfaces;

interface AssertsCookiesInterface
{
    public function assertCookieSent(string $name): void;

    public function assertCookieNotSent(string $name): void;

    public function assertCookieSentToUrl(string $name, string $url): void;

    public function assertCookieNotSentToUrl(string $name, string $url): void;

    public function assertCookieExists(string $name): void;

    public function assertCookieValue(string $name, string $expectedValue): void;

    /**
     * @param array<string, mixed> $attributes
     */
    public function assertCookieHasAttributes(string $name, array $attributes): void;

    public function assertCookieExpired(string $name): void;

    public function assertCookieNotExpired(string $name): void;

    public function assertCookieIsSecure(string $name): void;

    public function assertCookieIsHttpOnly(string $name): void;

    public function assertCookieIsHostOnly(string $name): void;
}