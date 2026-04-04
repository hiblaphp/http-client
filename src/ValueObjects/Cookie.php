<?php

declare(strict_types=1);

namespace Hibla\HttpClient\ValueObjects;

class Cookie
{
    private int $receivedAt;

    public function __construct(
        private string $name,
        private string $value,
        private ?int $expires = null,
        private ?string $domain = null,
        private ?string $path = null,
        private bool $secure = false,
        private bool $httpOnly = false,
        private ?int $maxAge = null,
        private ?string $sameSite = null
    ) {
        $this->receivedAt = time();
    }

    /**
     * Gets the cookie name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Gets the cookie value.
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Gets the cookie domain.
     */
    public function getDomain(): ?string
    {
        return $this->domain;
    }

    /**
     * Gets the cookie path, defaults to '/'.
     */
    public function getPath(): string
    {
        return $this->path ?? '/';
    }

    /**
     * Gets the cookie expiration timestamp.
     */
    public function getExpires(): ?int
    {
        return $this->expires;
    }

    /**
     * Gets the cookie max-age value.
     */
    public function getMaxAge(): ?int
    {
        return $this->maxAge;
    }

    /**
     * Checks if the cookie is secure.
     */
    public function isSecure(): bool
    {
        return $this->secure;
    }

    /**
     * Checks if the cookie is HTTP-only.
     */
    public function isHttpOnly(): bool
    {
        return $this->httpOnly;
    }

    /**
     * Gets the SameSite attribute value.
     */
    public function getSameSite(): ?string
    {
        return $this->sameSite;
    }

    /**
     * Checks if the cookie has expired.
     *
     * Per RFC 6265 section 4.1.2.2, Max-Age takes precedence over Expires when both
     * are present. Max-Age is a relative duration in seconds from when the
     * cookie was received, not a static flag.
     */
    public function isExpired(): bool
    {
        if ($this->maxAge !== null) {
            return time() >= ($this->receivedAt + $this->maxAge);
        }

        if ($this->expires !== null) {
            return time() >= $this->expires;
        }

        return false;
    }

    /**
     * Checks if this cookie matches the given domain and path.
     */
    public function matches(string $domain, string $path, bool $isSecure = false): bool
    {
        if ($this->isExpired()) {
            return false;
        }

        if ($this->secure && ! $isSecure) {
            return false;
        }

        if (! $this->matchesDomain($domain)) {
            return false;
        }

        if (! $this->matchesPath($path)) {
            return false;
        }

        return true;
    }

    /**
     * Checks if the cookie's domain matches the request domain.
     *
     * Per RFC 6265 section 5.1.3:
     * - Comparison is case-insensitive (both sides canonicalized to lowercase).
     * - Suffix matching is only valid for hostnames, not IP addresses.
     * - A leading dot on the cookie domain enables subdomain matching.
     */
    private function matchesDomain(string $requestDomain): bool
    {
        if ($this->domain === null) {
            return true;
        }

        $cookieDomain = strtolower(ltrim($this->domain, '.'));
        $requestDomain = strtolower($requestDomain);

        if ($cookieDomain === $requestDomain) {
            return true;
        }

        // Subdomain suffix match — only when domain starts with '.'
        // and the request host is not a raw IP address (RFC 6265 section 5.1.3).
        if (str_starts_with($this->domain, '.') && ! filter_var($requestDomain, FILTER_VALIDATE_IP)) {
            return str_ends_with($requestDomain, '.' . $cookieDomain);
        }

        return false;
    }

    /**
     * Checks if the cookie's path matches the request path.
     */
    private function matchesPath(string $requestPath): bool
    {
        if ($this->path === null || $this->path === '') {
            return true;
        }

        if ($this->path === $requestPath) {
            return true;
        }

        if (str_starts_with($requestPath, $this->path)) {
            return str_ends_with($this->path, '/') ||
                (isset($requestPath[strlen($this->path)]) && $requestPath[strlen($this->path)] === '/');
        }

        return false;
    }

    /**
     * Converts cookie to Set-Cookie header format.
     */
    public function toSetCookieHeader(): string
    {
        $parts = [$this->name . '=' . urlencode($this->value)];

        if ($this->expires !== null) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s T', $this->expires);
        }

        if ($this->maxAge !== null) {
            $parts[] = 'Max-Age=' . $this->maxAge;
        }

        if ($this->domain !== null) {
            $parts[] = 'Domain=' . $this->domain;
        }

        if ($this->path !== null) {
            $parts[] = 'Path=' . $this->path;
        }

        if ($this->secure) {
            $parts[] = 'Secure';
        }

        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }

        if ($this->sameSite !== null) {
            $parts[] = 'SameSite=' . $this->sameSite;
        }

        return implode('; ', $parts);
    }

    /**
     * Converts cookie to Cookie header format (name=value).
     */
    public function toCookieHeader(): string
    {
        return $this->name . '=' . $this->value;
    }

    /**
     * Creates a Cookie from a Set-Cookie header value.
     *
     * Per rfc6265 section 5.2:
     * - Strings containing CTL characters (except HTAB) are rejected.
     * - A cookie with an empty name is rejected.
     */
    public static function fromSetCookieHeader(string $setCookieHeader): ?self
    {
        // Reject CTL characters excluding HTAB (\x09) per rfc6265 section 5.2.
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $setCookieHeader)) {
            return null;
        }

        $parts = array_map('trim', explode(';', $setCookieHeader));

        if (\count($parts) === 0) {
            return null;
        }

        $nameValuePair = array_shift($parts);
        $equalPos = strpos($nameValuePair, '=');

        if ($equalPos === false) {
            return null;
        }

        $name = trim(substr($nameValuePair, 0, $equalPos));

        // Reject empty cookie names.
        if ($name === '') {
            return null;
        }

        $value = urldecode(substr($nameValuePair, $equalPos + 1));

        $expires = null;
        $maxAge = null;
        $domain = null;
        $path = null;
        $secure = false;
        $httpOnly = false;
        $sameSite = null;

        foreach ($parts as $part) {
            if (strcasecmp($part, 'Secure') === 0) {
                $secure = true;
            } elseif (strcasecmp($part, 'HttpOnly') === 0) {
                $httpOnly = true;
            } elseif (str_contains($part, '=')) {
                [$attrName, $attrValue] = array_map('trim', explode('=', $part, 2));

                switch (strtolower($attrName)) {
                    case 'expires':
                        $timestamp = strtotime($attrValue);
                        $expires = $timestamp !== false ? $timestamp : null;

                        break;
                    case 'max-age':
                        $maxAge = (int) $attrValue;

                        break;
                    case 'domain':
                        $domain = $attrValue;

                        break;
                    case 'path':
                        $path = $attrValue;

                        break;
                    case 'samesite':
                        $sameSite = $attrValue;

                        break;
                }
            }
        }

        return new self($name, $value, $expires, $domain, $path, $secure, $httpOnly, $maxAge, $sameSite);
    }

    /**
     * Returns the cookie as a string in Cookie header format.
     */
    public function __toString(): string
    {
        return $this->toCookieHeader();
    }
}