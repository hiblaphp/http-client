<?php

namespace Hibla\Http\Interfaces;

/**
 * Interface for server-side errors (5xx responses).
 */
interface ServerExceptionInterface extends RequestExceptionInterface
{
    /**
     * Get the HTTP status code.
     */
    public function getStatusCode(): ?int;
}
