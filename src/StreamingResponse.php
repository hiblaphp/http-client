<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

use Hibla\HttpClient\Exceptions\HttpStreamException;
use Hibla\HttpClient\Interfaces\StreamingResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Handles HTTP responses with streaming capabilities, allowing efficient
 * processing of large response bodies without loading them fully into memory.
 */
class StreamingResponse extends Response implements StreamingResponseInterface
{
    /**
     * Default chunk size for reading streams in bytes (8KB).
     */
    private const int CHUNK_SIZE = 8192;

    /**
     * The stream interface for reading response data.
     */
    private StreamInterface $stream;

    /**
     * Flag to track whether the stream has been consumed.
     */
    private bool $streamConsumed = false;

    /**
     * @param  StreamInterface  $stream  The stream containing the response body.
     * @param  int  $status  The HTTP status code.
     * @param  array<string, string|string[]>  $headers  Optional HTTP headers.
     */
    public function __construct(StreamInterface $stream, int $status, array $headers = [])
    {
        $this->stream = $stream;
        parent::__construct($stream, $status, $headers);
    }

    /**
     * @inheritDoc
     */
    public function getStream(): StreamInterface
    {
        return $this->stream;
    }

    /**
     * @inheritDoc
     *
     * Consumes the stream on first call and caches the result in a temporary
     * stream so repeated calls return the same content without re-reading.
     *
     * @throws HttpStreamException If the temporary stream cannot be opened.
     */
    public function body(): string
    {
        if ($this->streamConsumed) {
            return (string) $this->body;
        }

        $content = $this->stream->getContents();
        $this->streamConsumed = true;

        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            throw new HttpStreamException('Failed to open temporary stream');
        }

        fwrite($resource, $content);
        rewind($resource);
        $this->body = new Stream($resource);

        return $content;
    }

    /**
     * @inheritDoc
     */
    public function json(?string $key = null, $default = null): mixed
    {
        $decoded = json_decode($this->body(), true);

        if (! \is_array($decoded)) {
            return $default;
        }

        if ($key === null) {
            return $decoded;
        }

        return $this->getValueByKey($decoded, $key, $default);
    }

    /**
     * @inheritDoc
     */
    public function saveToFile(string $path): bool
    {
        $file = @fopen($path, 'wb');
        if ($file === false) {
            return false;
        }

        try {
            if ($this->stream->isSeekable()) {
                $this->stream->rewind();
            }

            while (! $this->stream->eof()) {
                $chunk = $this->stream->read(self::CHUNK_SIZE);
                if ($chunk === '') {
                    break;
                }
                if (fwrite($file, $chunk) === false) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            fclose($file);
        }
    }

    /**
     * @inheritDoc
     */
    public function streamTo($destination): bool
    {
        if (\is_string($destination)) {
            return $this->saveToFile($destination);
        }

        if (! \is_resource($destination)) {
            return false;
        }

        try {
            if ($this->stream->isSeekable()) {
                $this->stream->rewind();
            }

            while (! $this->stream->eof()) {
                $chunk = $this->stream->read(self::CHUNK_SIZE);
                if ($chunk === '') {
                    break;
                }
                if (@fwrite($destination, $chunk) === false) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
