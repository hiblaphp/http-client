<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

use Hibla\HttpClient\Exceptions\HttpStreamException;
use Hibla\HttpClient\Interfaces\StreamInterface;
use Hibla\HttpClient\Handlers\HttpStreamStateHandler;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;

/**
 * A perfectly clean, PSR-7 compliant HTTP stream with modern Promise-based 
 * asynchronous iteration capabilities.
 */
class Stream implements StreamInterface
{
    /** @var resource|null The underlying temporary stream resource. */
    private $resource;

    private HttpStreamStateHandler $handler;

    /**
     * Initializes a new Stream instance.
     *
     * @param  resource|null  $resource  Optional pre-existing PHP stream resource.
     *
     * @throws HttpStreamException if the provided argument is not a resource.
     */
    public function __construct($resource = null)
    {
        $this->resource = $resource ?? fopen('php://temp', 'w+b');
        
        if ($this->resource === false || ! \is_resource($this->resource)) {
            throw new HttpStreamException('Unable to create or use temporary stream');
        }

        $this->handler = new HttpStreamStateHandler($this->resource);
    }

    /**
     * Creates a new Stream instance from a string.
     *
     * @param  string  $content  The content to be streamed.
     *
     * @return self A new Stream instance.
     */
    public static function fromString(string $content): self
    {
        $stream = new self();
        if ($content !== '') {
            $stream->write($content); 
        }
        $stream->getHandler()->markEof();
        
        return $stream;
    }

    /**
     * Retrieve the internal state machine handler.
     * 
     * @internal Used by StreamingHandler to interact natively.
     */
    public function getHandler(): HttpStreamStateHandler
    {
        return $this->handler;
    }

    /**
     * {@inheritdoc}
     */
    public function readAsync(?int $length = null): PromiseInterface
    {
        return $this->handler->readAsync($length);
    }

    /**
     * {@inheritdoc}
     */
    public function readLineAsync(?int $maxLength = null): PromiseInterface
    {
        return $this->handler->readLineAsync($maxLength);
    }

    /**
     * {@inheritdoc}
     */
    public function readAllAsync(int $maxLength = 1048576): PromiseInterface
    {
        return $this->handler->readAllAsync($maxLength);
    }

    /**
     * {@inheritdoc}
     */
    public function pipeAsync(WritableStreamInterface $destination, array $options = []): PromiseInterface
    {
        return $this->handler->pipeAsync($destination, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function tell(): int
    {
        if ($this->resource === null) {
            throw new HttpStreamException('Stream is detached');
        }

        $result = @ftell($this->resource);

        if ($result === false) {
            throw new HttpStreamException('Unable to determine stream position');
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if ($this->resource === null) {
            throw new HttpStreamException('Stream is detached');
        }

        if (! $this->isSeekable()) {
            throw new HttpStreamException('Stream is not seekable');
        }

        if (fseek($this->resource, $offset, $whence) === -1) {
            throw new HttpStreamException("Unable to seek to position {$offset} with whence {$whence}");
        }

        $this->handler->clearBuffer();
    }

    /**
     * {@inheritdoc}
     */
    public function detach()
    {
        $res = $this->resource;
        $this->resource = null;
        $this->close();
        
        return $res;
    }

    /**
     * {@inheritdoc}
     */
    public function getSize(): ?int
    {
        if ($this->resource === null) {
            return null;
        }

        $stats = fstat($this->resource);
        return $stats['size'] ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function eof(): bool
    {
        if ($this->resource === null) {
            return true;
        }

        return $this->handler->isEof() || feof($this->resource);
    }

    /**
     * {@inheritdoc}
     */
    public function isSeekable(): bool
    {
        if ($this->resource === null) {
            return false;
        }

        $meta = stream_get_meta_data($this->resource);
        return $meta['seekable'];
    }

    /**
     * {@inheritdoc}
     */
    public function rewind(): void 
    { 
        $this->seek(0); 
    }

    /**
     * {@inheritdoc}
     */
    public function isWritable(): bool 
    { 
        if ($this->resource === null) {
            return false;
        }

        $meta = stream_get_meta_data($this->resource);
        $mode = $meta['mode'];

        return str_contains($mode, 'w') || str_contains($mode, 'a') || str_contains($mode, 'x') || str_contains($mode, 'c') || str_contains($mode, '+');
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $string): int 
    { 
        if ($this->resource === null) {
            throw new HttpStreamException('Stream is detached');
        }

        if (! $this->isWritable()) {
            throw new HttpStreamException('Cannot write to a non-writable stream');
        }

        $this->handler->writeToBuffer($string);
        
        return \strlen($string);
    }

    /**
     * {@inheritdoc}
     */
    public function isReadable(): bool
    {
        if ($this->resource === null) {
            return false;
        }

        $meta = stream_get_meta_data($this->resource);
        $mode = $meta['mode'];

        return str_contains($mode, 'r') || str_contains($mode, '+');
    }

    /**
     * {@inheritdoc}
     * 
     * Note: This is a blocking operation. Use readAsync() instead.
     */
    public function read(int $length): string
    {
        if ($this->resource === null) {
            throw new HttpStreamException('Stream is detached');
        }

        if (! $this->isReadable()) {
            throw new HttpStreamException('Cannot read from non-readable stream');
        }

        if ($length < 0) {
            throw new HttpStreamException('Length parameter cannot be negative');
        }

        if ($length === 0) {
            return '';
        }

        $data = fread($this->resource, $length);
        
        if ($data === false) {
            throw new HttpStreamException('Unable to read from stream');
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     * 
     * Note: This is a blocking operation. Use readAllAsync() instead.
     */
    public function getContents(): string
    {
        if ($this->resource === null) {
            throw new HttpStreamException('Stream is detached');
        }

        if (! $this->isReadable()) {
            throw new HttpStreamException('Cannot read from non-readable stream');
        }

        $data = stream_get_contents($this->resource);
        
        if ($data === false) {
            throw new HttpStreamException('Unable to read stream contents');
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata(?string $key = null)
    {
        if ($this->resource === null) {
            return $key ? null : [];
        }

        $meta = stream_get_meta_data($this->resource);
        return $key ? ($meta[$key] ?? null) : $meta;
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        $this->handler->close();
        if ($this->resource !== null) {
            @fclose($this->resource);
            $this->resource = null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        if (! $this->isReadable()) {
            return '';
        }

        try {
            if ($this->isSeekable()) {
                $this->rewind();
            }
            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }
}