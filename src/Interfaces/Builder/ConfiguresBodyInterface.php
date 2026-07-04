<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Builder;

use Hibla\Stream\Interfaces\ReadableStreamInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Fluent interface for setting the request body.
 *
 * The four strategies (raw, JSON, form, multipart) are mutually exclusive.
 * Each method replaces the body and adjusts the Content-Type header
 * as appropriate, except withMultipart() which removes any explicit
 * Content-Type so the transport can generate the multipart boundary.
 */
interface ConfiguresBodyInterface
{
    /**
     * Set the request body.
     *
     * Accepts a raw string, a standard PSR-7 StreamInterface, or a native
     * Hibla async ReadableStreamInterface for zero-memory streaming uploads.
     *
     * Does not modify the Content-Type header and callers are responsible
     * for setting an appropriate type via contentType() when needed.
     */
    public function body(string|StreamInterface|ReadableStreamInterface $content): static;

    /**
     * Set the request body as XML.
     *
     * Accepts a raw XML string or a SimpleXMLElement object.
     * Automatically sets Content-Type: application/xml.
     *
     * @param string|\SimpleXMLElement $xml
     */
    public function withXml(string|\SimpleXMLElement $xml): static;

    /**
     * JSON-encode $data and set it as the request body.
     *
     * Automatically sets Content-Type: application/json.
     *
     * @param array<string, mixed> $data
     *
     * @throws \InvalidArgumentException When $data cannot be JSON-encoded.
     */
    public function withJson(array $data): static;

    /**
     * URL-encode $data and set it as the request body.
     *
     * Automatically sets Content-Type: application/x-www-form-urlencoded.
     *
     * @param array<string, mixed> $data
     */
    public function withForm(array $data): static;

    /**
     * Set the body as multipart/form-data.
     *
     * Removes any explicit Content-Type header so the transport layer
     * can append the generated boundary parameter.
     *
     * @param array<string, mixed> $data
     */
    public function withMultipart(array $data): static;
}
