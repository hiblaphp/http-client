<?php

namespace Hibla\Http\Interfaces;

use Psr\Http\Message\ResponseInterface;

/**
 * Enhanced HTTP response interface with convenient helper methods.
 *
 * This interface extends the basic PSR-7 ResponseInterface with additional
 * methods for easier response handling and content access.
 */
interface EnhancedResponseInterface extends ResponseInterface
{
    /**
     * Get the response body as a string.
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
     */
    public function status(): int;

    /**
     * Get all response headers as a flattened array.
     *
     * @return array<string, string> An associative array of header names to values.
     */
    public function headers(): array;

    /**
     * Get a single response header by name.
     */
    public function header(string $name): ?string;

    /**
     * Determine if the response has a successful status code (2xx).
     */
    public function ok(): bool;

    /**
     * Determine if the response was successful. Alias for `ok()`.
     */
    public function successful(): bool;

    /**
     * Determine if the response indicates a client or server error (>=400).
     */
    public function failed(): bool;

    /**
     * Determine if the response has a client error status code (4xx).
     */
    public function clientError(): bool;

    /**
     * Determine if the response has a server error status code (5xx).
     */
    public function serverError(): bool;
}
