<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Exception;

/**
 * Represents a 4xx response — the server received and understood
 * the request but refused or could not fulfill it due to client error.
 *
 * These errors are generally not retryable without modifying
 * the request (auth, payload, permissions, etc.), except for
 * 429 Too Many Requests which carries retry-after semantics.
 */
interface ClientExceptionInterface extends RequestExceptionInterface
{
    /**
     * The HTTP status code returned by the server.
     * Returns null in the unlikely case the code could not be extracted.
     */
    public function getStatusCode(): ?int;
}
