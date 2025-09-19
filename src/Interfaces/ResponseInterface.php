<?php

namespace Hibla\Http\Interfaces;

/**
 * Represents an incoming server response to an HTTP request.
 *
 * Per PSR-7, this interface is immutable; all methods that might change state MUST
 * be implemented such that they retain the internal state of the current
 * message and return a new instance with the changed state.
 *
 * This interface extends the PSR-7 standard with additional convenient helper methods.
 */
interface ResponseInterface extends MessageInterface
{
    /**
     * Gets the response status code.
     *
     * The status code is a 3-digit integer result code of the server's attempt
     * to understand and satisfy the request.
     *
     * @return int Status code.
     */
    public function getStatusCode(): int;

    /**
     * Returns an instance with the specified status code and, optionally, reason phrase.
     *
     * If no reason phrase is specified, implementations MAY choose to default
     * to the RFC 7231 or IANA recommended reason phrase for the response's
     * status code.
     *
     * @param  int  $code  The 3-digit integer result code to set.
     * @param  string  $reasonPhrase  The reason phrase to use with the provided status code.
     * @return static A new instance with the specified status.
     *
     * @throws \InvalidArgumentException For invalid status code arguments.
     */
    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface;

    /**
     * Gets the response reason phrase associated with the status code.
     *
     * @return string Reason phrase; must return an empty string if none present.
     */
    public function getReasonPhrase(): string;

    /**
     * Get the response body as a string.
     *
     * @return string The full response body.
     */
    public function body(): string;

    /**
     * Get the response body decoded from JSON.
     *
     * @return array<string|int, mixed> The decoded JSON data. Returns an empty array on failure.
     */
    public function json(): array;

    /**
     * Get the HTTP status code.
     *
     * @return int The status code.
     */
    public function status(): int;

    /**
     * Get all response headers.
     *
     * @return array<string, string> An associative array of header names to values.
     */
    public function headers(): array;

    /**
     * Get a single response header by name.
     *
     * @param  string  $name  The case-insensitive header name.
     * @return string|null The header value, or null if the header does not exist.
     */
    public function header(string $name): ?string;

    /**
     * Determine if the response has a successful status code (2xx).
     *
     * @return bool True if the status code is between 200 and 299.
     */
    public function ok(): bool;

    /**
     * Determine if the response was successful. Alias for `ok()`.
     *
     * @return bool True if the response was successful.
     */
    public function successful(): bool;

    /**
     * Determine if the response indicates a client or server error (>=400).
     *
     * @return bool True if the status code is 400 or greater.
     */
    public function failed(): bool;

    /**
     * Determine if the response has a client error status code (4xx).
     *
     * @return bool True if the status code is between 400 and 499.
     */
    public function clientError(): bool;

    /**
     * Determine if the response has a server error status code (5xx).
     *
     * @return bool True if the status code is 500 or greater.
     */
    public function serverError(): bool;
}
