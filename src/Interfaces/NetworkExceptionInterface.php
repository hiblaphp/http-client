<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces;

/**
 * Interface for network-level errors.
 * These are errors that occur during network communication,
 * such as connection failures, timeouts, or DNS issues.
 */
interface NetworkExceptionInterface extends RequestExceptionInterface
{
}
