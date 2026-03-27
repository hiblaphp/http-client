<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Exception;

use Throwable;

/**
 * Base contract for all HTTP request-related exceptions.
 *
 * Every exception thrown by this library extends this interface,
 * making it the single catch-all type for callers that do not care
 * about the specific failure category.
 */
interface RequestExceptionInterface extends Throwable
{
    /**
     * The URL that was being requested when the exception occurred.
     * Returns null when the exception was raised before a URL was resolved.
     */
    public function getUrl(): ?string;
}