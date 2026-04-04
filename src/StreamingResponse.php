<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

use Hibla\HttpClient\Interfaces\StreamingResponseInterface;
use Hibla\HttpClient\Interfaces\StreamInterface;

use function Hibla\async;
use function Hibla\await;

class StreamingResponse extends Response implements StreamingResponseInterface
{
    /**
     * The unified stream interface.
     */
    private StreamInterface $stream;

    /**
     * Flag to track whether the stream has been consumed.
     */
    private bool $streamConsumed = false;

    /**
     * @param  StreamInterface  $stream  The enhanced stream.
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
     */
    public function body(): string
    {
        if ($this->streamConsumed) {
            return (string) $this->body;
        }

        $content = $this->stream->getContents();
        $this->streamConsumed = true;

        $this->body = Stream::fromString($content);

        return $content;
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
            $success = $this->streamTo($file);

            return $success;
        } finally {
            fclose($file);
        }
    }

    /**
     * @inheritDoc
     */
    public function streamTo(mixed $destination): bool
    {
        return await(
            async(function () use ($destination) {
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

                    while (($chunk = await($this->stream->readAsync(8192))) !== null) {
                        if ($chunk === '') {
                            continue;
                        }

                        if (@fwrite($destination, $chunk) === false) {
                            return false;
                        }
                    }

                    return true;
                } catch (\Throwable) {
                    return false;
                }
            })
        );
    }
}
