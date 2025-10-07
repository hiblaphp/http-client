<?php

namespace Hibla\HttpClient\Interfaces;

use Throwable;

/**
 * Base interface for all HTTP request-related exceptions.
 */
interface RequestExceptionInterface extends Throwable
{
    /**
     * Get the request URL if available.
     */
    public function getUrl(): ?string;
}
