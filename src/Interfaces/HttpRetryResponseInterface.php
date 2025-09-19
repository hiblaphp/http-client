<?php

namespace Hibla\Http\Interfaces;

/**
 * Interface for HTTP request objects that can be configured with retry logic.
 */
interface HttpRetryResponseInterface
{
    /**
     * Set request headers.
     *
     * @param  array<string, string>  $headers
     */
    public function headers(array $headers): mixed;

    /**
     * Set request body.
     */
    public function body(string $body): mixed;

    /**
     * Set JSON data for request.
     *
     * @param  array<string, mixed>  $json
     */
    public function json(array $json): mixed;

    /**
     * Set form data for request.
     *
     * @param  array<string, mixed>  $form
     */
    public function form(array $form): mixed;

    /**
     * Set request timeout.
     */
    public function timeout(int $timeout): mixed;

    /**
     * Set user agent string.
     */
    public function userAgent(string $userAgent): mixed;

    /**
     * Set SSL verification.
     */
    public function verifySSL(bool $verify): mixed;

    /**
     * Set bearer token authentication.
     */
    public function bearerToken(string $token): mixed;

    /**
     * Set basic authentication.
     */
    public function basicAuth(string $username, string $password): mixed;

    /**
     * Send the HTTP request.
     */
    public function send(string $method, string $url): mixed;
}
