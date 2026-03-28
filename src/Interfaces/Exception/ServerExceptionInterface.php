<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Exception;

/**
 * Represents a 5xx response — the server received and understood
 * the request but failed to fulfill it due to an internal error.
 *
 * These errors are candidates for retry logic since they typically
 * indicate a transient server-side failure rather than a problem
 * with the request itself.
 */
interface ServerExceptionInterface extends RequestExceptionInterface
{
    /**
     * The HTTP status code returned by the server.
     * Returns null in the unlikely case the code could not be extracted.
     */
    public function getStatusCode(): ?int;
}
