<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces;

/**
 * Extends ResponseInterface for responses whose body is consumed
 * incrementally rather than buffered in full.
 *
 * Implementations must not load the entire body into memory on construction.
 * The body is exposed as a live stream and optionally written to an output
 * destination chunk by chunk.
 */
interface StreamingResponseInterface extends ResponseInterface
{
    /**
     * Return the underlying enhanced Hibla stream.
     *
     * This stream supports both PSR-7 methods and Hibla's
     * asynchronous readAsync/pipe capabilities.
     */
    public function getStream(): StreamInterface;

    /**
     * Write the response body to a file at $path.
     *
     * Streams the body in chunks — does not buffer the full content in memory.
     * Creates or overwrites the file. Returns false on any I/O failure.
     */
    public function saveToFile(string $path): bool;

    /**
     * Stream the response body to a file path or an open resource handle.
     *
     * When $destination is a string it is treated as a file path.
     * When $destination is a resource the body is written directly to it.
     * Returns false on failure or when $destination is neither.
     *
     * @param string|resource $destination
     */
    public function streamTo(mixed $destination): bool;
}
