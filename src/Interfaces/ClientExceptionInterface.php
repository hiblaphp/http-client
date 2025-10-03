<?php

namespace Hibla\Http\Interfaces;

/**
 * Interface for client-side errors (4xx responses).
 * These are errors caused by invalid requests from the client.
 */
interface ClientExceptionInterface extends RequestExceptionInterface
{
    /**
     * Get the HTTP status code.
     */
    public function getStatusCode(): ?int;
}