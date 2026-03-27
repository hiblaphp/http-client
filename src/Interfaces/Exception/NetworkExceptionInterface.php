<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Exception;

/**
 * Represents a transport-level failure that occurred before or during
 * the HTTP exchange — connection refused, DNS resolution failure,
 * SSL handshake error, network unreachable, or timeout.
 *
 * These errors are candidates for retry logic because they are
 * not caused by the request content itself.
 */
interface NetworkExceptionInterface extends RequestExceptionInterface
{
}