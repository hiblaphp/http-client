<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Interfaces\StreamingResponseInterface;
use Hibla\HttpClient\Interfaces\StreamInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * A streaming HTTP response whose body is consumed incrementally.
 *
 * Extends Response with full StreamInterface delegation so callers can
 * read the body asynchronously without buffering it in full — no getter
 * required, the response itself is the stream.
 */
class StreamingResponse extends Response implements StreamingResponseInterface
{
    /**
     * Tracks whether the stream body has already been fully consumed.
     *
     * Once consumed the body is snapshotted into a rewindable in-memory
     * Stream so that subsequent body() calls remain consistent.
     */
    private bool $streamConsumed = false;

    private ?string $requestId = null;

    /**
     * @param StreamInterface              $stream  The live response stream.
     * @param int                          $status  HTTP status code.
     * @param array<string, string|string[]> $headers Optional response headers.
     */
    public function __construct(
        private readonly StreamInterface $stream,
        int $status,
        array $headers = [],
    ) {
        parent::__construct($stream, $status, $headers);
    }

    /**
     * @inheritDoc
     *
     * Reads the stream to exhaustion on first call, then snapshots the result
     * into a rewindable buffer so repeated calls are safe.
     */
    public function body(): string
    {
        if ($this->streamConsumed) {
            return (string) $this->body;
        }

        if ($this->stream->isSeekable()) {
            $this->stream->rewind();
        }

        $content = $this->stream->getContents();
        $this->streamConsumed = true;
        $this->body = Stream::fromString($content);

        return $content;
    }

    /**
     * @inheritDoc
     */
    public function readAsync(?int $length = null): PromiseInterface
    {
        return $this->stream->readAsync($length);
    }

    /**
     * @inheritDoc
     */
    public function readLineAsync(?int $maxLength = null): PromiseInterface
    {
        return $this->stream->readLineAsync($maxLength);
    }

    /**
     * @inheritDoc
     */
    public function readAllAsync(int $maxLength = 1048576): PromiseInterface
    {
        return $this->stream->readAllAsync($maxLength);
    }

    /**
     * @inheritDoc
     */
    public function read(int $length): string
    {
        return $this->stream->read($length);
    }

    /**
     * @inheritDoc
     */
    public function getContents(): string
    {
        return $this->stream->getContents();
    }

    /**
     * @inheritDoc
     */
    public function getSize(): ?int
    {
        return $this->stream->getSize();
    }

    /**
     * @inheritDoc
     */
    public function getMetadata(?string $key = null): mixed
    {
        return $this->stream->getMetadata($key);
    }

    /**
     * @inheritDoc
     */
    public function tell(): int
    {
        return $this->stream->tell();
    }

    /**
     * @inheritDoc
     */
    public function eof(): bool
    {
        return $this->stream->eof();
    }

    /**
     * @inheritDoc
     */
    public function isSeekable(): bool
    {
        return $this->stream->isSeekable();
    }

    /**
     * @inheritDoc
     */
    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    /**
     * @inheritDoc
     */
    public function isWritable(): bool
    {
        return $this->stream->isWritable();
    }

    /**
     * @inheritDoc
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
    }

    /**
     * @inheritDoc
     */
    public function rewind(): void
    {
        $this->stream->rewind();
    }

    /**
     * @inheritDoc
     */
    public function write(string $string): int
    {
        return $this->stream->write($string);
    }

    /**
     * @inheritDoc
     */
    public function detach(): mixed
    {
        return $this->stream->detach();
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        if ($this->requestId !== null && extension_loaded('curl')) {
            Loop::cancelCurlRequest($this->requestId);
            $this->requestId = null;
        }

        $this->stream->close();
    }

    /**
     * Links the cURL handle ID to this response for cancellation.
     *
     * @internal use by Handler for cancellation
     */
    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    /**
     * @inheritDoc
     */
    public function __toString(): string
    {
        return (string) $this->stream;
    }
}
