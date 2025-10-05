<?php

namespace Hibla\Http\Interfaces;

/**
 * Interface for network-level errors.
 * These are errors that occur during network communication,
 * such as connection failures, timeouts, or DNS issues.
 */
interface NetworkExceptionInterface extends RequestExceptionInterface
{
}
