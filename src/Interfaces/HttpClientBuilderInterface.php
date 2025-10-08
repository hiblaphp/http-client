<?php

namespace Hibla\HttpClient\Interfaces;

use Hibla\HttpClient\Response;
use Hibla\Promise\Interfaces\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Fluent HTTP client builder interface.
 *
 * Provides a rich, chainable interface for constructing and configuring
 * HTTP requests with various options like headers, timeouts, authentication,
 * caching, retries, and more.
 */
interface HttpClientBuilderInterface extends RequestInterface
{
    /**
     * Set the Content-Type header.
     */
    public function contentType(string $type): self;

    /**
     * Set the Accept header.
     */
    public function accept(string $type): self;

    /**
      * Set a single URL parameter for URI template substitution.
      *
      * @param  string  $key  The parameter name
      * @param  mixed  $value  The parameter value
      * @return self For fluent method chaining.
      */
    public function withUrlParameter(string $key, $value): self;

    /**
     * Set URL parameters for URI template substitution.
     *
     * Supports both simple substitution {param} and reserved expansion {+param}.
     * Reserved expansion preserves special characters in the value.
     *
     * @param  array<string, mixed>  $parameters  The URL parameters
     * @return self For fluent method chaining.
     */
    public function withUrlParameters(array $parameters): self;

    /**
     * Set a Bearer token for authorization.
     */
    public function withToken(string $token): self;

    /**
     * Set the User-Agent header.
     */
    public function withUserAgent(string $userAgent): self;

    /**
     * Configure HTTP Basic Authentication.
     */
    public function withBasicAuth(string $username, string $password): self;

    /**
     * Configure HTTP Digest Authentication.
     */
    public function withDigestAuth(string $username, string $password): self;

    /**
     * Set the raw request body content.
     */
    public function body(string $content): self;

    /**
     * Set JSON data as the request body.
     *
     * @param array<string, mixed> $data Data to be JSON-encoded
     */
    public function withJson(array $data): self;

    /**
     * Set form data as the request body (application/x-www-form-urlencoded).
     *
     * @param array<string, mixed> $data Form fields
     */
    public function withForm(array $data): self;

    /**
     * Set multipart form data as the request body.
     *
     * @param array<string, mixed> $data Multipart fields
     */
    public function withMultipart(array $data): self;

    /**
     * Set the request timeout in seconds.
     */
    public function timeout(int $seconds): self;

    /**
     * Set the connection timeout in seconds.
     */
    public function connectTimeout(int $seconds): self;

    /**
     * Configure redirect behavior.
     */
    public function redirects(bool $follow = true, int $max = 5): self;

    /**
     * Enable or disable SSL certificate verification.
     */
    public function verifySSL(bool $verify = true): self;

    /**
     * Set the HTTP protocol version (e.g., '1.0', '1.1', '2.0').
     */
    public function httpVersion(string $version): self;

    /**
     * Force HTTP/2 protocol.
     */
    public function http2(): self;

    /**
     * Force HTTP/3 protocol.
     */
    public function http3(): self;

    /**
     * Execute a GET request.
     *
     * @param array<string, scalar|null> $query Query parameters
     * @return PromiseInterface<Response>
     */
    public function get(string $url, array $query = []): PromiseInterface;

    /**
     * Execute a POST request.
     *
     * @param array<string, mixed> $data Request data
     * @return PromiseInterface<Response>
     */
    public function post(string $url, array $data = []): PromiseInterface;

    /**
     * Execute a PUT request.
     *
     * @param array<string, mixed> $data Request data
     * @return PromiseInterface<Response>
     */
    public function put(string $url, array $data = []): PromiseInterface;

    /**
     * Execute a DELETE request.
     *
     * @return PromiseInterface<Response>
     */
    public function delete(string $url): PromiseInterface;

    /**
     * Execute a PATCH request.
     *
     * @param array<string, mixed> $data Request data
     * @return PromiseInterface<Response>
     */
    public function patch(string $url, array $data = []): PromiseInterface;

    /**
     * Execute an OPTIONS request.
     *
     * @return PromiseInterface<Response>
     */
    public function options(string $url): PromiseInterface;

    /**
     * Execute a request with a custom HTTP method.
     *
     * @return PromiseInterface<Response>
     */
    public function send(string $method, string $url): PromiseInterface;
}
