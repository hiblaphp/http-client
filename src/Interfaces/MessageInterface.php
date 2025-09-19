<?php

namespace Hibla\Http\Interfaces;

/**
 * Representation of an HTTP message.
 *
 * This interface defines the methods common to requests and responses.
 * Instances of this interface are considered immutable; all methods that might
 * change state MUST be implemented such that they retain the internal state of
 * the current message and return a new instance with the changed state.
 *
 * @see http://www.ietf.org/rfc/rfc7230.txt
 * @see http://www.ietf.org/rfc/rfc7231.txt
 */
interface MessageInterface
{
    /**
     * Retrieves the HTTP protocol version as a string.
     *
     * The string MUST contain only the HTTP version number (e.g., "1.1", "1.0").
     *
     * @return string HTTP protocol version.
     */
    public function getProtocolVersion(): string;

    /**
     * Returns an instance with the specified HTTP protocol version.
     *
     * The version string MUST contain only the HTTP version number (e.g., "1.1", "1.0").
     *
     * @param  string  $version  HTTP protocol version.
     * @return static A new instance with the specified protocol version.
     */
    public function withProtocolVersion(string $version): MessageInterface;

    /**
     * Retrieves all message header values.
     *
     * The keys represent the header name as it will be sent over the wire, and
     * each value is an array of strings associated with the header.
     *
     * @return array<string, string[]> Returns an associative array of the message's headers.
     */
    public function getHeaders(): array;

    /**
     * Checks if a header exists by the given case-insensitive name.
     *
     * @param  string  $name  Case-insensitive header field name.
     * @return bool Returns true if any header names match the given header
     *              name using a case-insensitive string comparison. Returns false if
     *              no matching header name is found in the message.
     */
    public function hasHeader(string $name): bool;

    /**
     * Retrieves a message header value by the given case-insensitive name.
     *
     * This method returns an array of all the header values of the given
     * case-insensitive header name.
     *
     * If the header does not appear in the message, this method MUST return an
     * empty array.
     *
     * @param  string  $name  Case-insensitive header field name.
     * @return string[] An array of string values as provided for the given
     *                  header. If the header does not exist, an empty array MUST be returned.
     */
    public function getHeader(string $name): array;

    /**
     * Retrieves a comma-separated string of the values for a single header.
     *
     * This method returns all of the header values of the given
     * case-insensitive header name as a string concatenated together using
     * a comma.
     *
     * NOTE: Not all header values may be appropriately represented using
     * comma concatenation. For such headers, use getHeader() instead
     * and supply your own delimiter when concatenating.
     *
     * If the header does not appear in the message, this method MUST return
     * an empty string.
     *
     * @param  string  $name  Case-insensitive header field name.
     * @return string A string of values as provided for the given header
     *                concatenated together using a comma. If the header does not exist,
     *                an empty string MUST be returned.
     */
    public function getHeaderLine(string $name): string;

    /**
     * Returns an instance with the provided value replacing the specified header.
     *
     * While header names are case-insensitive, the casing of the header will
     * be preserved by this function, and returned from getHeaders().
     *
     * @param  string  $name  Case-insensitive header field name.
     * @param  string|string[]  $value  Header value(s).
     * @return static A new instance with the provided header, replacing any
     *                existing values of that header.
     */
    public function withHeader(string $name, $value): MessageInterface;

    /**
     * Returns an instance with the specified header appended with the given value.
     *
     * Existing values for the specified header will be maintained. The new
     * value(s) will be appended to the existing list. If the header did not
     * exist previously, it will be added.
     *
     * @param  string  $name  Case-insensitive header field name to add.
     * @param  string|string[]  $value  Header value(s).
     * @return static A new instance with the specified header appended.
     */
    public function withAddedHeader(string $name, $value): MessageInterface;

    /**
     * Returns an instance without the specified header.
     *
     * Header resolution MUST be case-insensitive.
     *
     * @param  string  $name  Case-insensitive header field name to remove.
     * @return static A new instance without the specified header.
     */
    public function withoutHeader(string $name): MessageInterface;

    /**
     * Gets the body of the message.
     *
     * @return StreamInterface Returns the body as a stream.
     */
    public function getBody(): StreamInterface;

    /**
     * Returns an instance with the specified message body.
     *
     * The body MUST be a StreamInterface object.
     *
     * @param  StreamInterface  $body  Body.
     * @return static A new instance with the specified body.
     *
     * @throws \InvalidArgumentException When the body is not valid.
     */
    public function withBody(StreamInterface $body): MessageInterface;
}
