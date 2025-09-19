<?php

namespace Hibla\Http\Interfaces;

/**
 * Representation of an outgoing, client-side HTTP request.
 *
 * Per the HTTP specification, this interface includes properties for
 * each of the following:
 *
 * - Protocol version
 * - HTTP method
 * - URI
 * - Headers
 * - Message body
 *
 * During construction, implementations MUST attempt to set the Host header from
 * a provided URI if no Host header is provided.
 *
 * Requests are considered immutable; all methods that might change state MUST
 * be implemented such that they retain the internal state of the current
 * message and return an instance that contains the changed state.
 */
interface RequestInterface extends MessageInterface
{
    /**
     * Retrieves the message's request target.
     *
     * Retrieves the message's request-target either as it will be sent (for
     * absolute-form), or as it was received (for origin-form, authority-form,
     * or asterisk-form).
     *
     * In most cases, this will be the origin-form of the URI, unless a
     * different form is provided upon instantiation.
     */
    public function getRequestTarget(): string;

    /**
     * Returns an instance with the specific request-target.
     *
     * @return static A new instance with the specified request-target.
     */
    public function withRequestTarget(string $requestTarget): RequestInterface;

    /**
     * Retrieves the HTTP method of the request.
     *
     * @return string Returns the request method.
     */
    public function getMethod(): string;

    /**
     * Returns an instance with the provided HTTP method.
     *
     * While HTTP method names are typically uppercase, this method MUST NOT
     * modify the given string.
     *
     * @param  string  $method  Case-sensitive method.
     * @return static A new instance with the specified method.
     *
     * @throws \InvalidArgumentException for invalid HTTP methods.
     */
    public function withMethod(string $method): RequestInterface;

    /**
     * Retrieves the URI instance.
     *
     * This method MUST return a UriInterface instance.
     *
     * @see http://tools.ietf.org/html/rfc3986#section-4.3
     *
     * @return UriInterface Returns a UriInterface instance
     *                      representing the URI of the request.
     */
    public function getUri(): UriInterface;

    /**
     * Returns an instance with the provided URI.
     *
     * This method MUST update the Host header of the returned request by
     * default if the URI contains a host component. If the URI does not
     * contain a host component, any existing Host header MUST be preserved.
     *
     * You can opt-in to preserving the original Host header by setting
     * `$preserveHost` to `true`.
     *
     * @param  UriInterface  $uri  New request URI to use.
     * @param  bool  $preserveHost  Preserve the original state of the Host header.
     * @return static A new instance with the specified URI.
     */
    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface;
}
