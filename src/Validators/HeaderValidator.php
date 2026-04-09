<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Validators;

/**
 * Validates HTTP header names, header values, and request methods
 * against RFC 9110 (HTTP Semantics) and RFC 9112 (HTTP/1.1).
 *
 * RFC 9110 obsoletes RFC 7230 as of June 2022. All rules here
 * reference RFC 9110 section 5 (Fields) and RFC 9112 section 2.1 (Request Line).
 *
 * Designed as a stateless final class so it can be used as a pure
 * utility without instantiation, and is trivially unit-testable.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9110#section-5
 * @see https://www.rfc-editor.org/rfc/rfc9112#section-2.1
 */
final class HeaderValidator
{
    /**
     * RFC 9110 section 5.1 — field-name = token
     *
     * token = 1*tchar
     * tchar = "!" / "#" / "$" / "%" / "&" / "'" / "*" / "+" / "-" / "." /
     *         "^" / "_" / "`" / "|" / "~" / DIGIT / ALPHA
     *
     * Colons, spaces, parentheses, and all control characters are excluded.
     *
     * NOTE: \z is used instead of $ to anchor at the absolute end of the
     * string. In PHP's PCRE engine, $ matches before a trailing \n, which
     * would silently allow header names and HTTP methods ending with a line-
     * feed — a potential injection vector. \z never matches before \n.
     */
    private const string TOKEN_PATTERN = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/';

    /**
     * RFC 9110 section 5.5 — characters that are unconditionally forbidden in
     * field values: NUL (0x00), LF (0x0A), CR (0x0D).
     *
     * "Field values containing CR, LF, or NUL characters are invalid
     *  and dangerous."  — RFC 9110 section 5.5
     */
    private const string FORBIDDEN_VALUE_CHARS = '/[\x00\x0A\x0D]/';

    /**
     * RFC 9110 section 5.5 — control characters other than HTAB (0x09) are
     * forbidden anywhere in a field value. DEL (0x7F) is also excluded.
     *
     * Permitted whitespace inside a value is limited to SP (0x20) and
     * HTAB (0x09) between visible characters (field-vchar tokens).
     */
    private const string FORBIDDEN_CTL_PATTERN = '/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/';

    /**
     * Prevent instantiation — this class is a pure static utility.
     */
    private function __construct()
    {
    }

    /**
     * Assert that a header field name is a valid RFC 9110 token.
     *
     * @throws \InvalidArgumentException On any violation.
     */
    public static function assertValidName(string $name): void
    {
        if (! self::isValidName($name)) {
            throw new \InvalidArgumentException(
                $name === ''
                    ? 'Header name must not be empty.'
                    : sprintf(
                        'Invalid header name "%s": only RFC 9110 tchar characters are permitted ' .
                            '(ALPHA, DIGIT, and !#$%%&\'*+-.^_`|~).',
                        $name,
                    )
            );
        }
    }

    /**
     * Return true when the name is a valid RFC 9110 token.
     */
    public static function isValidName(string $name): bool
    {
        return $name !== '' && preg_match(self::TOKEN_PATTERN, $name) === 1;
    }

    /**
     * Assert that a header field value conforms to RFC 9110 section 5.5.
     *
     * Rules enforced:
     *   1. CR (0x0D), LF (0x0A), and NUL (0x00) are unconditionally rejected.
     *   2. Control characters other than HTAB (0x09) are rejected.
     *   3. DEL (0x7F) is rejected.
     *   4. Leading or trailing SP / HTAB is rejected — RFC 9110 section 5.5 states
     *      "a field value does not include leading or trailing whitespace."
     *   5. obs-fold (CRLF + whitespace) is implicitly rejected by rule 1 and
     *      is explicitly deprecated by RFC 9110 section 5.5.
     *
     * Note: obs-text (0x80–0xFF) is intentionally permitted. RFC 9110 ABNF
     * still allows these bytes for legacy interoperability. If you are
     * building a strict US-ASCII-only client you may tighten this separately.
     *
     * @throws \InvalidArgumentException On any violation.
     */
    public static function assertValidValue(string $value): void
    {
        $error = self::detectValueError($value);

        if ($error !== null) {
            throw new \InvalidArgumentException($error);
        }
    }

    /**
     * Return true when the value is a valid RFC 9110 section 5.5 field-value.
     */
    public static function isValidValue(string $value): bool
    {
        return self::detectValueError($value) === null;
    }

    /**
     * Assert that an HTTP method is a valid RFC 9110 section 9.1 token.
     *
     * RFC 9110 section 9.1: "The method token is case-sensitive … method = token"
     * The token ABNF is identical to the field-name ABNF, so it can reuse the
     * same pattern. Upper-casing is the caller's responsibility (see Request).
     *
     * @throws \InvalidArgumentException On any violation.
     */
    public static function assertValidMethod(string $method): void
    {
        if (! self::isValidMethod($method)) {
            throw new \InvalidArgumentException(
                $method === ''
                    ? 'HTTP method must not be empty.'
                    : sprintf(
                        'Invalid HTTP method "%s": only RFC 9110 token characters are permitted.',
                        $method,
                    )
            );
        }
    }

    /**
     * Return true when the method string is a valid RFC 9110 token.
     */
    public static function isValidMethod(string $method): bool
    {
        return self::isValidName($method);
    }

    /**
     * Run all field-value rules and return the first error message, or null
     * when the value is clean.
     *
     * Separated from assertValidValue() so that isValidValue() can reuse the
     * logic without catching exceptions, which keeps the bool-returning path
     * allocation-free.
     */
    private static function detectValueError(string $value): ?string
    {
        // Rule 1: CR, LF, NUL — unconditionally forbidden (RFC 9110 section 5.5).
        if (preg_match(self::FORBIDDEN_VALUE_CHARS, $value) === 1) {
            return 'Invalid header value: CR (\r / 0x0D), LF (\n / 0x0A), and NUL (0x00) ' .
                'are forbidden per RFC 9110 section 5.5.';
        }

        // Rules 2 & 3: Other control characters, including DEL (0x7F)
        // (RFC 9110 section 5.5). HTAB (0x09) is the sole permitted control-range
        // byte and is intentionally absent from FORBIDDEN_CTL_PATTERN.
        if (preg_match(self::FORBIDDEN_CTL_PATTERN, $value) === 1) {
            return 'Invalid header value: control characters other than HTAB (0x09), ' .
                'including DEL (0x7F), are forbidden per RFC 9110 section 5.5.';
        }

        // Rule 4: No leading or trailing SP / HTAB (RFC 9110 section 5.5).
        if ($value !== '' && (
            $value[0] === ' ' || $value[0] === "\t" ||
            $value[-1] === ' ' || $value[-1] === "\t"
        )) {
            return 'Invalid header value: leading or trailing whitespace (SP/HTAB) ' .
                'is forbidden per RFC 9110 section 5.5.';
        }

        return null;
    }
}
