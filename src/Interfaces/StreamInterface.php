<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces;

use Hibla\Stream\Interfaces\PromiseReadableInterface;
use Psr\Http\Message\StreamInterface as Psr7StreamInterface;

/**
 * The modern, expressive HTTP Stream interface for Hibla.
 *
 * Provides full PSR-7 compatibility combined with high-performance,
 * non-blocking Promise-based operations.
 */
interface StreamInterface extends Psr7StreamInterface, PromiseReadableInterface
{
}
