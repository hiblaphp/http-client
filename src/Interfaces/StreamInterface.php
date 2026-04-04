<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Psr\Http\Message\StreamInterface as Psr7StreamInterface;

/**
 * The modern, expressive HTTP Stream interface for Hibla.
 * 
 * Provides full PSR-7 compatibility combined with high-performance, 
 * non-blocking Promise-based operations.
 */
interface StreamInterface extends Psr7StreamInterface
{
    /**
     * Asynchronously reads a chunk of data.
     *
     * @param int|null $length Maximum bytes to read.
     * @return PromiseInterface<string|null> Resolves with data, or null at EOF.
     */
    public function readAsync(?int $length = null): PromiseInterface;

    /**
     * Asynchronously reads data until a newline character is encountered.
     *
     * @param int|null $maxLength A safeguard to limit the line length.
     * @return PromiseInterface<string|null> Resolves with the line, or null at EOF.
     */
    public function readLineAsync(?int $maxLength = null): PromiseInterface;

    /**
     * Asynchronously reads the entire stream into a single string.
     *
     * @param int $maxLength A safeguard to prevent excessive memory usage.
     * @return PromiseInterface<string> Resolves with the complete contents.
     */
    public function readAllAsync(int $maxLength = 1048576): PromiseInterface;

    /**
     * Forwards all data from this stream to a destination, automatically 
     * handling backpressure.
     *
     * @param WritableStreamInterface $destination The stream to receive the data.
     * @param array{end?: bool} $options Configure piping behavior.
     * @return PromiseInterface<int> Resolves with the total number of bytes piped.
     */
    public function pipeAsync(WritableStreamInterface $destination, array $options = []): PromiseInterface;
}
