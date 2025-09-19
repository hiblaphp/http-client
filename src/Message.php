<?php

namespace Hibla\Http;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

/**
 * An abstract base class providing a common implementation for HTTP messages.
 *
 * This class implements the `MessageInterface` and provides the foundational logic
 * for handling protocol versions, headers, and message bodies, which is then
 * extended by the concrete `Request` and `Response` classes.
 *
 * @see MessageInterface
 */
abstract class Message implements MessageInterface
{
    /**
     * The HTTP protocol version.
     */
    protected string $protocol = '1.1';

    /**
     * An associative array of HTTP headers.
     *
     * @var array<string, string[]>
     */
    protected array $headers = [];

    /**
     * A map of lowercase header names to their original case.
     *
     * @var array<string, string>
     */
    protected array $headerNames = [];

    /** @var StreamInterface The message body. */
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
        $header = strtolower($name);
        if (! isset($this->headerNames[$header])) {
            return [];
        }

        $header = $this->headerNames[$header];

        return $this->headers[$header];
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
     */
    public function withHeader(string $name, $value): MessageInterface
    {
        $value = $this->normalizeHeaderValue($value);
        $normalized = strtolower($name);

        $new = clone $this;
        if (isset($new->headerNames[$normalized])) {
            unset($new->headers[$new->headerNames[$normalized]]);
        }
        $new->headerNames[$normalized] = $name;
        $new->headers[$name] = $value;

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withAddedHeader(string $name, $value): MessageInterface
    {
        if (! $this->hasHeader($name)) {
            return $this->withHeader($name, $value);
        }

        $header = $this->headerNames[strtolower($name)];
        $new = clone $this;
        $new->headers[$header] = array_merge($this->headers[$header], $this->normalizeHeaderValue($value));

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withoutHeader(string $name): MessageInterface
    {
        $normalized = strtolower($name);
        if (! isset($this->headerNames[$normalized])) {
            return $this;
        }

        $header = $this->headerNames[$normalized];
        $new = clone $this;
        unset($new->headers[$header], $new->headerNames[$normalized]);

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
     * Replaces all headers with a new set from an associative array.
     *
     * This method correctly handles case-insensitivity and preserves the original
     * casing of the header names provided.
     *
     * @param  array<string, string|string[]>  $headers  An associative array of headers to set.
     */
    protected function setHeaders(array $headers): void
    {
        $this->headerNames = [];
        $this->headers = [];

        foreach ($headers as $header => $value) {
            if (is_int($header)) {
                $header = (string) $header;
            }
            $value = $this->normalizeHeaderValue($value);
            $normalized = strtolower($header);
            if (isset($this->headerNames[$normalized])) {
                $header = $this->headerNames[$normalized];
                $this->headers[$header] = array_merge($this->headers[$header], $value);
            } else {
                $this->headerNames[$normalized] = $header;
                $this->headers[$header] = $value;
            }
        }
    }

    /**
     * Normalizes a header value to ensure it is an array of strings.
     *
     * @param  mixed  $value  The header value to normalize.
     * @return string[] The normalized header value as an array of strings.
     *
     * @throws \InvalidArgumentException If the value is an empty array.
     */
    private function normalizeHeaderValue($value): array
    {
        if (! is_array($value)) {
            if (is_object($value) && method_exists($value, '__toString')) {
                return [trim((string) $value)];
            }

            if (is_scalar($value) || $value === null) {
                return [trim((string) $value)];
            }

            return [trim(var_export($value, true))];
        }

        if (count($value) === 0) {
            throw new \InvalidArgumentException('Header value must be a string or a non-empty array of strings.');
        }

        return array_map(function ($v) {
            if (is_object($v) && method_exists($v, '__toString')) {
                return trim((string) $v);
            }

            if (is_scalar($v) || $v === null) {
                return trim((string) $v);
            }

            return trim(var_export($v, true));
        }, array_values($value));
    }
}
