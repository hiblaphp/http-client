<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Interfaces;

interface AssertsCookiesInterface
{
    public function assertCookieSent(string $name): void;

    public function assertCookieExists(string $name): void;

    public function assertCookieValue(string $name, string $expectedValue): void;
}
