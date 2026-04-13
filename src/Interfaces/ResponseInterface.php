<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces;

use Psr\Http\Message\ResponseInterface as Psr7ResponseInterface;

/**
 * Extends the PSR-7 ResponseInterface with convenience methods
 * that cover the most common response inspection tasks without
 * requiring callers to work directly with streams or raw headers.
 *
 * All methods are read-only — this interface adds no mutation surface
 * beyond what PSR-7 already provides via withStatus() and withHeader().
 */
interface ResponseInterface extends Psr7ResponseInterface
{
    /**
     * Return the entire response body as a string.
     *
     * Repeated calls must return the same content regardless of the
     * underlying stream's cursor position.
     */
    public function body(): string;

    /**
     * Decode the response body as JSON.
     *
     * When $key is null the full decoded structure is returned.
     * When $key is provided it is resolved using dot notation, so
     * 'user.address.city' traverses nested arrays.
     * If the key cannot be found, or the body is not valid JSON,
     * $default is returned instead.
     *
     * @param string|null $key Optional dot-notation path to a specific value.
     * @param mixed $default Fallback value when the key is absent or decode fails.
     *
     * @return mixed
     */
    public function json(?string $key = null, mixed $default = null): mixed;

    /**
     * Decode the response body as a SimpleXMLElement.
     *
     * Returns null if the body is empty or not valid XML.
     */
    public function xml(): ?\SimpleXMLElement;

    /**
     * Return the HTTP status code.
     *
     * Convenience alias for PSR-7's getStatusCode() that fits
     * naturally in fluent conditional chains.
     */
    public function status(): int;

    /**
     * Return all response headers as a flat associative array.
     *
     * Header names are normalised to lowercase. When a header has
     * multiple values they are joined with ', ' per RFC 7230.
     *
     * @return array<string, string>
     */
    public function headers(): array;

    /**
     * Return a single response header value by name.
     *
     * The lookup is case-insensitive. Returns null when the header
     * is absent rather than an empty string, so callers can use
     * a simple null-check.
     */
    public function header(string $name): ?string;

    /**
     * Return true when the status code is in the 2xx range.
     */
    public function successful(): bool;

    /**
     * Return true when the status code is 400 or above.
     *
     * This is the logical inverse of successful() and covers
     * both client errors (4xx) and server errors (5xx).
     */
    public function failed(): bool;

    /**
     * Return true when the status code is in the 4xx range.
     */
    public function clientError(): bool;

    /**
     * Return true when the status code is in the 5xx range.
     */
    public function serverError(): bool;

    /**
     * Return the HTTP protocol version that was actually negotiated
     * for this response (e.g. '1.1', '2', '3').
     *
     * Returns null when the version could not be determined, which
     * can happen with mocked responses in tests.
     */
    public function getHttpVersion(): ?string;

    /**
     * Return a fully qualified HTTP version string (e.g. 'HTTP/2').
     *
     * Falls back to constructing the string from the PSR-7 protocol
     * version when no negotiated version has been recorded.
     */
    public function getHttpVersionString(): string;
}
