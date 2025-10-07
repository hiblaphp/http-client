<?php

namespace Hibla\HttpClient;

use Hibla\HttpClient\Exceptions\HttpStreamException;
use Psr\Http\Message\StreamInterface;

/**
 * A PSR-7 compliant stream representation of a PHP resource.
 */
class Stream implements StreamInterface
{
    /** @var resource|null The underlying PHP stream resource. */
    private $resource;
    private ?string $uri;
    private bool $seekable;
    private bool $readable;
    private bool $writable;
    private ?int $size = null;

    private const READ_WRITE_HASH = [
        'read' => [
            'r' => true,
            'w+' => true,
            'r+' => true,
            'x+' => true,
            'c+' => true,
            'rb' => true,
            'w+b' => true,
            'r+b' => true,
            'x+b' => true,
            'c+b' => true,
            'rt' => true,
            'w+t' => true,
            'r+t' => true,
            'x+t' => true,
            'c+t' => true,
            'a+' => true,
        ],
        'write' => [
            'w' => true,
            'w+' => true,
            'rw' => true,
            'r+' => true,
            'x+' => true,
            'c+' => true,
            'wb' => true,
            'w+b' => true,
            'r+b' => true,
            'x+b' => true,
            'c+b' => true,
            'w+t' => true,
            'r+t' => true,
            'x+t' => true,
            'c+t' => true,
            'a' => true,
            'a+' => true,
        ],
    ];

    /**
     * Initializes a new Stream instance.
     *
     * @param  resource  $resource  The PHP stream resource.
     * @param  string|null  $uri  The URI associated with the stream, if any.
     *
     * @throws HttpStreamException if the provided argument is not a resource.
     */
    public function __construct($resource, ?string $uri = null)
    {
        if (! is_resource($resource)) {
            throw new HttpStreamException('Stream must be a resource');
        }

        $this->resource = $resource;
        $this->uri = $uri;

        $meta = stream_get_meta_data($this->resource);
        $this->seekable = $meta['seekable'];
        $this->readable = isset(self::READ_WRITE_HASH['read'][$meta['mode']]);
        $this->writable = isset(self::READ_WRITE_HASH['write'][$meta['mode']]);
    }

    /**
     * Create a new stream from string content.
     *
     * @param  string  $content  The content for the stream
     * @return self A new stream instance
     *
     * @throws HttpStreamException If stream creation fails
     */
    public static function fromString(string $content): self
    {
        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            throw new HttpStreamException('Unable to create temporary stream');
        }

        if ($content !== '') {
            fwrite($resource, $content);
            rewind($resource);
        }

        return new self($resource);
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
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * {@inheritdoc}
     */
    public function close(): void
    {
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }
        $this->detach();
    }

    /**
     * {@inheritdoc}
     */
    public function detach()
    {
        if (! is_resource($this->resource)) {
            return null;
        }

        $result = $this->resource;
        $this->resource = null;
        $this->size = null;
        $this->uri = null;
        $this->readable = false;
        $this->writable = false;
        $this->seekable = false;

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getSize(): ?int
    {
        if ($this->size !== null) {
            return $this->size;
        }

        if (! is_resource($this->resource)) {
            return null;
        }

        if ($this->uri !== null) {
            clearstatcache(true, $this->uri);
        }

        $stats = fstat($this->resource);
        if (is_array($stats) && isset($stats['size'])) {
            $this->size = $stats['size'];

            return $this->size;
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function tell(): int
    {
        if (! is_resource($this->resource)) {
            throw new HttpStreamException('Stream is detached');
        }

        $result = ftell($this->resource);
        if ($result === false) {
            throw new HttpStreamException('Unable to determine stream position');
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function eof(): bool
    {
        if (! is_resource($this->resource)) {
            return true;
        }

        return feof($this->resource);
    }

    /**
     * {@inheritdoc}
     */
    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    /**
     * {@inheritdoc}
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (! $this->isSeekable()) {
            throw new HttpStreamException('Stream is not seekable');
        }

        if (! is_resource($this->resource)) {
            throw new HttpStreamException('Stream is detached');
        }

        if (fseek($this->resource, $offset, $whence) === -1) {
            throw new HttpStreamException('Unable to seek to stream position '.$offset.' with whence '.var_export($whence, true));
        }
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
        return $this->writable;
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $string): int
    {
        if (! $this->isWritable()) {
            throw new HttpStreamException('Cannot write to a non-writable stream');
        }

        if (! is_resource($this->resource)) {
            throw new HttpStreamException('Stream is detached');
        }

        $this->size = null; // Invalidate cached size

        $result = fwrite($this->resource, $string);
        if ($result === false) {
            throw new HttpStreamException('Unable to write to stream');
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function isReadable(): bool
    {
        return $this->readable;
    }

    /**
     * {@inheritdoc}
     */
    public function read(int $length): string
    {
        if (! $this->isReadable()) {
            throw new HttpStreamException('Cannot read from non-readable stream');
        }

        if ($length < 0) {
            throw new HttpStreamException('Length parameter cannot be negative');
        }

        if ($length === 0) {
            return '';
        }

        if (! is_resource($this->resource)) {
            throw new HttpStreamException('Stream is detached');
        }

        $result = fread($this->resource, $length);
        if ($result === false) {
            throw new HttpStreamException('Unable to read from stream');
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getContents(): string
    {
        if (! $this->isReadable()) {
            throw new HttpStreamException('Cannot read from non-readable stream');
        }

        if (! is_resource($this->resource)) {
            throw new HttpStreamException('Stream is detached');
        }

        $contents = stream_get_contents($this->resource);
        if ($contents === false) {
            throw new HttpStreamException('Unable to read stream contents');
        }

        return $contents;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata(?string $key = null)
    {
        if (! is_resource($this->resource)) {
            return $key !== null ? null : [];
        }

        $meta = stream_get_meta_data($this->resource);
        if ($key === null) {
            return $meta;
        }

        return $meta[$key] ?? null;
    }
}
