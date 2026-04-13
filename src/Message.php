<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

use Hibla\HttpClient\Validators\HeaderValidator;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

/**
 * An abstract base class providing a common implementation for HTTP messages.
 *
 * This class implements the `MessageInterface` and provides the foundational logic
 * for handling protocol versions, headers, and message bodies, which is then
 * extended by the concrete `Request` and `Response` classes.
 *
 * Header names and values are validated against RFC 9110 (HTTP Semantics) and
 * RFC 9112 (HTTP/1.1), which obsolete RFC 7230 as of June 2022. Validation is
 * delegated to {@see HeaderValidator} so that the rules are testable in isolation
 * and reusable across the entire HTTP client pipeline.
 *
 * @see MessageInterface
 * @see HeaderValidator
 * @see https://www.rfc-editor.org/rfc/rfc9110#section-5
 * @see https://www.rfc-editor.org/rfc/rfc9112
 */
abstract class Message implements MessageInterface
{
    /**
     * The HTTP protocol version.
     */
    protected string $protocol = '2.0';

    /**
     * An associative array of HTTP headers, keyed by original header name casing.
     *
     * @var array<string, string[]>
     */
    protected array $headers = [];

    /**
     * A map of lowercase header names to their original-case equivalents.
     *
     * Maintained in parallel with {@see $headers} to provide case-insensitive
     * header lookup while preserving the casing supplied by the caller.
     *
     * @var array<string, string>
     */
    protected array $headerNames = [];

    /**
     * The message body.
     */
    protected StreamInterface $body;

    /**
     * {@inheritdoc}
     */
    public function getProtocolVersion(): string
    {
        return $this->protocol;
    }

    /**
     * {@inheritdoc}
     */
    public function withProtocolVersion(string $version): MessageInterface
    {
        if ($this->protocol === $version) {
            return $this;
        }

        $new = clone $this;
        $new->protocol = $version;

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * {@inheritdoc}
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headerNames[strtolower($name)]);
    }

    /**
     * {@inheritdoc}
     */
    public function getHeader(string $name): array
    {
        $normalized = strtolower($name);

        if (! isset($this->headerNames[$normalized])) {
            return [];
        }

        return $this->headers[$this->headerNames[$normalized]];
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    /**
     * {@inheritdoc}
     *
     * @throws \InvalidArgumentException If the header name or any value violates RFC 9110.
     */
    public function withHeader(string $name, $value): MessageInterface
    {
        HeaderValidator::assertValidName($name);

        $value = $this->normalizeHeaderValue($value);
        $normalized = strtolower($name);

        $new = clone $this;

        // Remove any previously registered header that maps to the same
        // case-insensitive key so the new casing takes precedence.
        if (isset($new->headerNames[$normalized])) {
            unset($new->headers[$new->headerNames[$normalized]]);
        }

        $new->headerNames[$normalized] = $name;
        $new->headers[$name] = $value;

        return $new;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \InvalidArgumentException If the header name or any value violates RFC 9110.
     */
    public function withAddedHeader(string $name, $value): MessageInterface
    {
        HeaderValidator::assertValidName($name);

        if (! $this->hasHeader($name)) {
            return $this->withHeader($name, $value);
        }

        $value = $this->normalizeHeaderValue($value);
        $normalized = strtolower($name);
        $existing = $this->headerNames[$normalized];

        $new = clone $this;
        $new->headers[$existing] = array_merge($this->headers[$existing], $value);

        return $new;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \InvalidArgumentException If the header name violates RFC 9110.
     */
    public function withoutHeader(string $name): MessageInterface
    {
        HeaderValidator::assertValidName($name);

        $normalized = strtolower($name);

        if (! isset($this->headerNames[$normalized])) {
            return $this;
        }

        $existing = $this->headerNames[$normalized];

        $new = clone $this;
        unset($new->headers[$existing], $new->headerNames[$normalized]);

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    /**
     * {@inheritdoc}
     */
    public function withBody(StreamInterface $body): MessageInterface
    {
        if ($body === $this->body) {
            return $this;
        }

        $new = clone $this;
        $new->body = $body;

        return $new;
    }

    /**
     * Replace all headers with a new set from an associative array.
     *
     * Validates every name and value pair against RFC 9110 before storing,
     * handles case-insensitive deduplication, and preserves the original
     * casing of the names provided by the caller.
     *
     * When duplicate case-insensitive names are encountered their values are
     * merged in the order they appear, matching the behaviour of
     * {@see withAddedHeader()}.
     *
     * @internal Called by the concrete subclass constructors and transport layer.
     *
     * @param array<string, string|string[]> $headers
     *
     * @throws \InvalidArgumentException If any name or value violates RFC 9110.
     */
    protected function setHeaders(array $headers): void
    {
        $this->headerNames = [];
        $this->headers = [];

        foreach ($headers as $name => $value) {
            // PHP silently coerces integer array keys — restore the string form.
            if (\is_int($name)) {
                $name = (string) $name;
            }

            HeaderValidator::assertValidName($name);

            $value = $this->normalizeHeaderValue($value);
            $normalized = strtolower($name);

            if (isset($this->headerNames[$normalized])) {
                // Duplicate case-insensitive key — merge values under the
                // casing that was registered first.
                $existing = $this->headerNames[$normalized];
                $this->headers[$existing] = array_merge($this->headers[$existing], $value);
            } else {
                $this->headerNames[$normalized] = $name;
                $this->headers[$name] = $value;
            }
        }
    }

    /**
     * Coerce a raw header value into a validated, trimmed array of strings.
     *
     * Accepts scalars, null, objects with {@see __toString()}, and non-empty
     * arrays of the above. Every individual string produced is validated by
     * {@see HeaderValidator::assertValidValue()} before it is returned, so
     * callers can trust that the resulting array is RFC 9110 §5.5 compliant.
     *
     * @param mixed $value
     *
     * @return string[]
     *
     * @throws \InvalidArgumentException If the value is an empty array or any
     *                                   individual string violates RFC 9110 §5.5.
     */
    private function normalizeHeaderValue(mixed $value): array
    {
        if (! \is_array($value)) {
            $str = $this->coerceToString($value);
            HeaderValidator::assertValidValue($str);

            return [$str];
        }

        if (\count($value) === 0) {
            throw new \InvalidArgumentException(
                'Header value must be a string or a non-empty array of strings.',
            );
        }

        return array_map(function (mixed $item): string {
            $str = $this->coerceToString($item);
            HeaderValidator::assertValidValue($str);

            return $str;
        }, array_values($value));
    }

    /**
     * Coerce a single scalar-like value to its string representation.
     *
     * Intentionally does NOT call {@see trim()} — leading and trailing
     * whitespace is rejected by {@see HeaderValidator::assertValidValue()}
     * per RFC 9110 §5.5, and silently stripping it would mask malformed
     * input rather than surface it early.
     *
     * @param mixed $value
     */
    private function coerceToString(mixed $value): string
    {
        if (\is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        if (\is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return var_export($value, true);
    }
}
