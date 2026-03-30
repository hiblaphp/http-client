<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Builder;

/**
 * Fluent interface for setting request headers.
 *
 * All methods return a new instance — implementations must
 * preserve immutability so chains can branch without side effects.
 *
 * Note: withHeader(), withAddedHeader(), and withoutHeader() are
 * intentionally omitted here — they are inherited from PSR-7's
 * MessageInterface and must not be redeclared with a different signature.
 */
interface ConfiguresHeadersInterface
{
    /**
     * Set the Content-Type header.
     *
     * @param string $type Media type value (e.g. 'application/json').
     */
    public function contentType(string $type): static;

    /**
     * Set the Accept header.
     *
     * @param string $type Desired media type (e.g. 'application/json').
     */
    public function accept(string $type): static;

    /**
     * Set Content-Type to application/json.
     *
     * Shorthand for contentType('application/json').
     */
    public function asJson(): static;

    /**
     * Set Content-Type to application/x-www-form-urlencoded.
     *
     * Shorthand for contentType('application/x-www-form-urlencoded').
     */
    public function asForm(): static;

    /**
     * Set the User-Agent header.
     *
     * Overrides any globally configured user agent for this request only.
     */
    public function withUserAgent(string $userAgent): static;

    /**
     * Merge multiple headers into the current set.
     *
     * Existing headers with the same name are replaced, not appended.
     *
     * @param  array<string, string|string[]>  $headers
     */
    public function withHeaders(array $headers): static;
}
