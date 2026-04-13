<?php

declare(strict_types=1);

namespace Hibla\HttpClient\ValueObjects;

class Cookie
{
    private int $receivedAt;

    private int $createdAt;

    public function __construct(
        private string $name,
        private string $value,
        private ?int $expires = null,
        private ?string $domain = null,
        private ?string $path = null,
        private bool $secure = false,
        private bool $httpOnly = false,
        private ?int $maxAge = null,
        private ?string $sameSite = null,
        private bool $hostOnly = false,
        private bool $persistent = false,
        ?int $createdAt = null,
    ) {
        $this->receivedAt = time();
        $this->createdAt = $createdAt ?? time();
    }

    /**
     * Checks if the given string is a valid RFC 2616 token for use as a cookie name.
     *
     * Rejects empty strings, control characters, and HTTP separator characters.
     */
    public static function isValidName(string $name): bool
    {
        return $name !== ''
            && preg_match('/[\x00-\x1F\x7F-\xFF()<>@,;:\\\\"\/\[\]?={} \t]/', $name) === 0;
    }

    /**
     * Checks if the given string conforms to the cookie-octet character set
     * defined in RFC 6265 section 4.1.1.
     *
     * Allowed octets: %x21 / %x23-2B / %x2D-3A / %x3C-5B / %x5D-7E
     *
     * DQUOTE-wrapped values ("cookie-octets") are also accepted per the RFC grammar.
     */
    public static function isValidValue(string $value): bool
    {
        $inner = (str_starts_with($value, '"') && str_ends_with($value, '"') && strlen($value) >= 2)
            ? substr($value, 1, -1)
            : $value;

        return $inner === ''
            || preg_match('/[^\x21\x23-\x2B\x2D-\x3A\x3C-\x5B\x5D-\x7E]/', $inner) === 0;
    }

    /**
     * Assert the cookie name is a valid RFC 2616 token.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValidName(string $name): void
    {
        if (! self::isValidName($name)) {
            throw new \InvalidArgumentException(
                $name === ''
                    ? 'Cookie name must not be empty (RFC 6265 section 4.1.1).'
                    : sprintf(
                        'Cookie name "%s" contains characters not permitted in an HTTP token '
                            . '(RFC 2616 section 2.2, referenced by RFC 6265 section 4.1.1).',
                        $name,
                    )
            );
        }
    }

    /**
     * Assert the cookie value contains only cookie-octet characters.
     *
     * Allowed octets per RFC 6265 section 4.1.1:
     *   %x21 / %x23-2B / %x2D-3A / %x3C-5B / %x5D-7E
     *
     * For values containing characters outside the allowed set (e.g. spaces,
     * commas), encode first using Base64 as recommended by RFC 6265 section 4.1.1:
     *   assertValidValue(base64_encode($arbitraryValue))
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValidValue(string $value): void
    {
        if (! self::isValidValue($value)) {
            throw new \InvalidArgumentException(sprintf(
                'Cookie value "%s" contains characters outside the cookie-octet set '
                    . 'defined in RFC 6265 section 4.1.1. '
                    . 'For arbitrary data, Base64-encode the value first: base64_encode($value).',
                $value,
            ));
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function getPath(): string
    {
        return $this->path ?? '/';
    }

    public function getExpires(): ?int
    {
        return $this->expires;
    }

    public function getMaxAge(): ?int
    {
        return $this->maxAge;
    }

    public function isSecure(): bool
    {
        return $this->secure;
    }

    public function isHttpOnly(): bool
    {
        return $this->httpOnly;
    }

    public function getSameSite(): ?string
    {
        return $this->sameSite;
    }

    /**
     * Whether this cookie is host-only.
     *
     * Per RFC 6265 section 5.3 step 6: when the Set-Cookie header contains no
     * Domain attribute, the host-only-flag is set to true and the cookie may
     * only be sent to the exact origin host that set it.
     */
    public function isHostOnly(): bool
    {
        return $this->hostOnly;
    }

    /**
     * Whether this cookie is persistent.
     *
     * Per RFC 6265 section 5.3 step 3: a cookie is persistent when a Max-Age
     * or Expires attribute was present. Session cookies (persistent-flag false)
     * should be discarded when the session ends.
     */
    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    /**
     * Return the creation timestamp.
     *
     * Per RFC 6265 section 5.3 step 11.3: when a cookie is overwritten in the
     * store, the creation-time of the old cookie is preserved on the replacement.
     */
    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    /**
     * Checks if the cookie has expired.
     *
     * Per RFC 6265 section 4.1.2.2, Max-Age takes precedence over Expires when
     * both are present. Max-Age is a relative duration in seconds from when the
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
     * Checks if this cookie matches the given domain, path, and scheme.
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
     * Per RFC 6265 section 5.3 step 6:
     * - Host-only cookies must match the exact origin domain (no subdomain matching).
     * - Domain cookies use suffix matching rules from section 5.1.3.
     */
    private function matchesDomain(string $requestDomain): bool
    {
        $requestDomain = strtolower($requestDomain);

        // Host-only: must match the exact domain the cookie was set on.
        // Per RFC 6265 section 5.3 step 6 — no Domain attribute was present
        // in the Set-Cookie header, so subdomain matching is not permitted.
        if ($this->hostOnly) {
            return $this->domain !== null
                && $requestDomain === strtolower($this->domain);
        }

        // A domain-less, non-host-only cookie has no scope — don't match anything.
        // This state should not occur in practice; fromSetCookieHeader always
        // sets hostOnly=true when no Domain attribute is present and originHost is given.
        if ($this->domain === null) {
            return false;
        }

        $cookieDomain = strtolower(ltrim($this->domain, '.'));

        if ($cookieDomain === $requestDomain) {
            return true;
        }

        // Fixed: filter_var returns the value or false. Explicitly compare against false.
        if (str_starts_with($this->domain, '.') && filter_var($requestDomain, FILTER_VALIDATE_IP) === false) {
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
            return str_ends_with($this->path, '/')
                || (isset($requestPath[strlen($this->path)]) && $requestPath[strlen($this->path)] === '/');
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
     * Per RFC 6265 section 5.3:
     * - Strings containing CTL characters (except HTAB) are rejected.
     * - A cookie with an empty or invalid name is rejected.
     * - A cookie with a value outside the cookie-octet set is rejected.
     * - When no Domain attribute is present, the host-only-flag is set to true
     *   and the domain is set to the canonicalized request-host (step 6).
     * - The persistent-flag is set to true when Max-Age or Expires is present (step 3).
     *
     * @param string $setCookieHeader The raw Set-Cookie header value.
     * @param string|null $originHost The host that sent the response. Required
     *                                for correct host-only-flag behaviour.
     */
    public static function fromSetCookieHeader(string $setCookieHeader, ?string $originHost = null): ?self
    {
        // Fixed: Explicitly compare preg_match against 1 (match found)
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $setCookieHeader) === 1) {
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
        $rawValue = substr($nameValuePair, $equalPos + 1);

        // Validate before decoding — percent-encoded values like 'hello%20world'
        // are valid cookie-octets and must be checked in their encoded form.
        if (! self::isValidName($name) || ! self::isValidValue($rawValue)) {
            return null;
        }

        $value = urldecode($rawValue);
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

        // RFC 6265 section 5.3 step 6:
        // If no Domain attribute was present, set host-only-flag to true and
        // use the canonicalized request-host as the cookie's domain so that
        // the cookie is only sent back to the exact origin host.
        if ($domain === null && $originHost !== null) {
            $domain = strtolower($originHost);
            $hostOnly = true;
        } else {
            $hostOnly = false;
        }

        // RFC 6265 section 5.3 step 3:
        // persistent-flag is true when Max-Age or Expires was present.
        $persistent = $maxAge !== null || $expires !== null;

        return new self(
            $name,
            $value,
            $expires,
            $domain,
            $path,
            $secure,
            $httpOnly,
            $maxAge,
            $sameSite,
            $hostOnly,
            $persistent,
        );
    }

    /**
     * Returns the cookie as a string in Cookie header format.
     */
    public function __toString(): string
    {
        return $this->toCookieHeader();
    }
}
