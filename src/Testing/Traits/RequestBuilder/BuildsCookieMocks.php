<?php

namespace Hibla\Http\Testing\Traits\RequestBuilder;

use Hibla\Http\Testing\Utilities\CookieManager;

trait BuildsCookieMocks
{
    abstract protected function getRequest();

    /**
     * Configure the mock to set cookies via Set-Cookie headers.
     */
    public function setCookies(array $cookies): self
    {
        $cookieService = new CookieManager;
        $cookieService->mockSetCookies($this->getRequest(), $cookies);
        return $this;
    }

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
    ): self {
        $config = compact('value', 'path', 'domain', 'expires', 'secure', 'httpOnly', 'sameSite');
        $config = array_filter($config, fn($v) => $v !== null);

        return $this->setCookies([$name => $config]);
    }
}