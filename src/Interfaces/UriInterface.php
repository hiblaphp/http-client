<?php

namespace Hibla\Http\Interfaces;

/**
 * Value object representing a Uniform Resource Identifier (URI).
 *
 * This interface provides methods for interacting with the various parts of a URI.
 * Instances of this interface are considered immutable; all methods that might
 * change state MUST be implemented such that they retain the internal state of
 * the current message and return a new instance with the changed state.
 *
 * @see https://tools.ietf.org/html/rfc3986
 */
interface UriInterface
{
    /**
     * Retrieve the scheme component of the URI.
     *
     * @return string The URI scheme.
     */
    public function getScheme(): string;

    /**
     * Retrieve the authority component of the URI.
     * The authority syntax is: [user-info@]host[:port]
     *
     * @return string The URI authority, in "[user-info@]host[:port]" format.
     */
    public function getAuthority(): string;

    /**
     * Retrieve the user information component of the URI.
     *
     * @return string The URI user information, in "user[:password]" format.
     */
    public function getUserInfo(): string;

    /**
     * Retrieve the host component of the URI.
     *
     * @return string The URI host.
     */
    public function getHost(): string;

    /**
     * Retrieve the port component of the URI.
     *
     * @return int|null The URI port or null if no port is specified.
     */
    public function getPort(): ?int;

    /**
     * Retrieve the path component of the URI.
     *
     * @return string The URI path.
     */
    public function getPath(): string;

    /**
     * Retrieve the query string of the URI.
     *
     * @return string The URI query string.
     */
    public function getQuery(): string;

    /**
     * Retrieve the fragment component of the URI.
     *
     * @return string The URI fragment.
     */
    public function getFragment(): string;

    /**
     * Return an instance with the specified scheme.
     *
     * @param  string  $scheme  The scheme to use for the new instance.
     * @return static A new instance with the specified scheme.
     */
    public function withScheme(string $scheme): UriInterface;

    /**
     * Return an instance with the specified user information.
     *
     * @param  string  $user  The user name to use for the new instance.
     * @param  string|null  $password  The password associated with the user.
     * @return static A new instance with the specified user information.
     */
    public function withUserInfo(string $user, ?string $password = null): UriInterface;

    /**
     * Return an instance with the specified host.
     *
     * @param  string  $host  The hostname to use for the new instance.
     * @return static A new instance with the specified host.
     */
    public function withHost(string $host): UriInterface;

    /**
     * Return an instance with the specified port.
     *
     * @param  int|null  $port  The port to use for the new instance; a null value removes the port information.
     * @return static A new instance with the specified port.
     */
    public function withPort(?int $port): UriInterface;

    /**
     * Return an instance with the specified path.
     *
     * @param  string  $path  The path to use for the new instance.
     * @return static A new instance with the specified path.
     */
    public function withPath(string $path): UriInterface;

    /**
     * Return an instance with the specified query string.
     *
     * @param  string  $query  The query string to use for the new instance.
     * @return static A new instance with the specified query string.
     */
    public function withQuery(string $query): UriInterface;

    /**
     * Return an instance with the specified URI fragment.
     *
     * @param  string  $fragment  The fragment to use for the new instance.
     * @return static A new instance with the specified fragment.
     */
    public function withFragment(string $fragment): UriInterface;

    /**
     * Return the string representation of the URI.
     *
     * @see https://tools.ietf.org/html/rfc3986#section-3
     */
    public function __toString(): string;
}
