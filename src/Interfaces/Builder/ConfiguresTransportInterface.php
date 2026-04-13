<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Builder;

/**
 * Fluent interface for configuring request timeouts, redirects,
 * SSL verification, and HTTP protocol version negotiation.
 *
 * These settings control the behaviour of the underlying transport
 * and are independent of the request content.
 */
interface ConfiguresTransportInterface
{
    /**
     * Set the maximum time in seconds the entire request may take.
     *
     * A value of 0 disables the timeout, which is appropriate for
     * long-running SSE connections but should be avoided for regular requests.
     */
    public function timeout(int $seconds): static;

    /**
     * Set the maximum time in seconds allowed for establishing the connection.
     *
     * Applies to the TCP handshake and SSL negotiation phases only.
     * Does not affect the time allowed for the response to arrive.
     */
    public function connectTimeout(int $seconds): static;

    /**
     * Configure automatic redirect following.
     *
     * @param bool $follow Whether to follow Location headers automatically.
     * @param int $max Maximum number of redirects before giving up.
     */
    public function redirects(bool $follow = true, int $max = 5): static;

    /**
     * Enable or disable SSL peer certificate verification.
     *
     * Disabling verification is strongly discouraged in production
     * and should only be used in controlled test environments.
     */
    public function verifySSL(bool $verify = true): static;

    /**
     * Set the HTTP protocol version for this request by version string.
     *
     * Accepted values: '1.0', '1.1', '2', '2.0', '3', '3.0'.
     * The transport will fall back gracefully when the requested
     * version is not supported by the server.
     */
    public function httpVersion(string $version): static;

    /**
     * Request HTTP/1.1 specifically.
     *
     * Useful when connecting to servers or proxies known to have
     * issues with HTTP/2 or HTTP/3 negotiation.
     */
    public function http1(): static;

    /**
     * Negotiate HTTP/2 with automatic fallback to HTTP/1.1.
     */
    public function http2(): static;

    /**
     * Negotiate HTTP/3 with automatic fallback to HTTP/1.1.
     */
    public function http3(): static;
}
