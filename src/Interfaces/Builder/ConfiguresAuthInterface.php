<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Builder;

/**
 * Fluent interface for configuring request authentication.
 *
 * The three strategies (token, basic, digest) are mutually exclusive —
 * setting one replaces any previously configured auth on the instance.
 */
interface ConfiguresAuthInterface
{
    /**
     * Set a token on the Authorization header.
     *
     * If $token already contains the type prefix (e.g. 'Bearer abc')
     * the prefix is stripped before constructing the header value
     * to prevent duplication.
     *
     * @param  string  $token  The raw token value.
     * @param  string  $type   Token scheme (default: 'Bearer').
     */
    public function withToken(string $token, string $type = 'Bearer'): static;

    /**
     * Configure HTTP Basic Authentication.
     *
     * Credentials are passed to the transport layer rather than
     * base64-encoded into the Authorization header directly, so
     * the transport can handle encoding and challenge responses correctly.
     */
    public function withBasicAuth(string $username, string $password): static;

    /**
     * Configure HTTP Digest Authentication.
     *
     * The transport layer handles the challenge-response handshake.
     */
    public function withDigestAuth(string $username, string $password): static;
}
