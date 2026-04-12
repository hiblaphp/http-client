<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Validators;

use Hibla\HttpClient\Exceptions\NetworkException;
use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

/**
 * Validates and sanitizes URIs to prevent SSRF, Header Injection, and Cross-Origin leakage.
 *
 * @internal
 */
final class UriValidator
{
    /**
     * Validates that the URL does not contain control characters (CR, LF, NUL, etc.).
     * This prevents Request-Line and Header smuggling attacks.
     *
     * @throws InvalidArgumentException
     */
    public static function assertNoControlCharacters(string $url): void
    {
        if (\preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw new InvalidArgumentException('URL contains invalid control characters.');
        }
    }

    /**
     * Asserts that the URI uses a safe, permitted HTTP scheme.
     * This prevents SSRF protocol pivoting (e.g., file://, gopher://, dict://).
     *
     * @throws NetworkException
     */
    public static function assertAllowedScheme(UriInterface $uri): void
    {
        $scheme = \strtolower($uri->getScheme());

        // allow empty schemes (relative URIs) or explicitly http/https.
        if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
            throw new NetworkException(
                "The scheme '{$scheme}' is not supported. Only 'http' and 'https' are allowed.",
                0,
                null,
                (string) $uri
            );
        }
    }

    /**
     * Asserts that an IP address is not in a private or reserved range.
     *
     * @throws NetworkException
     */
    public static function assertPublicIp(string $ip, string $originalUrl): void
    {
        // FILTER_FLAG_NO_PRIV_RANGE: Blocks 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16
        // FILTER_FLAG_NO_RES_RANGE:  Blocks 127.0.0.0/8, 169.254.0.0/16, etc.
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) {
            throw new NetworkException(
                "Access to private or reserved network address '{$ip}' is restricted.",
                0,
                null,
                $originalUrl
            );
        }
    }

    /**
     * Determines if two URIs are cross-domain (RFC 6454).
     * Automatically handles edge cases like IPv6 Zone IDs (RFC 6874).
     */
    public static function isCrossDomain(UriInterface $original, UriInterface $new): bool
    {
        $originalHost = \strtolower($original->getHost());
        $newHost = \strtolower($new->getHost());

        // Strip IPv6 Zone IDs (e.g., [::1%25eth0] -> [::1]) per RFC 6874
        $originalHost = \preg_replace('/%[^\]]+\]/', ']', $originalHost) ?? $originalHost;
        $newHost = \preg_replace('/%[^\]]+\]/', ']', $newHost) ?? $newHost;

        // Normalize unicode and punycode to ASCII via UTS#46 so that
        // münchen.de and xn--mnchen-3ya.de compare as equal (same origin),
        // while Cyrillic/Greek lookalikes remain distinct (cross-origin).
        if (\function_exists('idn_to_ascii')) {
            $normalizedOriginal = \idn_to_ascii($originalHost, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);
            $normalizedNew = \idn_to_ascii($newHost, \IDNA_DEFAULT, \INTL_IDNA_VARIANT_UTS46);

            if ($normalizedOriginal !== false) {
                $originalHost = $normalizedOriginal;
            }

            if ($normalizedNew !== false) {
                $newHost = $normalizedNew;
            }
        }

        return $originalHost !== $newHost
            || $original->getPort() !== $new->getPort()
            || $original->getScheme() !== $new->getScheme();
    }
}
