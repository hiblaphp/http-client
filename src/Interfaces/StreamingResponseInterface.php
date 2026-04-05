<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces;

/**
 * Extends ResponseInterface for responses whose body is consumed
 * incrementally rather than buffered in full.
 *
 * Implementations must not load the entire body into memory on construction.
 * By also extending StreamInterface, the response itself exposes async read
 * methods directly — callers never need to unwrap an inner stream object.
 */
interface StreamingResponseInterface extends ResponseInterface, StreamInterface
{
}
