<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Utils;

use Hibla\Promise\Promise;
use Hibla\Stream\Interfaces\ReadableStreamInterface;
use Psr\Http\Message\StreamInterface;

use function Hibla\await;

/**
 * Adapts an asynchronous, push-based Hibla ReadableStreamInterface into a
 * pull-based PSR-7 StreamInterface.
 *
 * It uses Fiber suspension via `await()` to support synchronous PSR-7 reads
 * (`read`, `getContents`, `__toString`) without blocking the event loop!
 *
 * @internal
 */
class HiblaStreamAdapter implements StreamInterface
{
    private bool $eof = false;

    private string $buffer = '';

    public function __construct(
        public readonly ReadableStreamInterface $hiblaStream,
        private readonly ?int $size = null
    ) {
        $this->hiblaStream->on('end', function (): void {
            $this->eof = true;
        });
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        $this->hiblaStream->close();
    }

    /**
     * @inheritDoc
     */
    public function detach()
    {
        $this->close();

        return null;
    }

    /**
     * @inheritDoc
     */
    public function getSize(): ?int
    {
        return $this->size;
    }

    /**
     * @inheritDoc
     */
    public function tell(): int
    {
        throw new \RuntimeException('Cannot determine position of an asynchronous event stream');
    }

    /**
     * @inheritDoc
     */
    public function eof(): bool
    {
        return $this->eof && $this->buffer === '';
    }

    /**
     * @inheritDoc
     */
    public function isSeekable(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('Cannot seek an asynchronous event stream');
    }

    /**
     * @inheritDoc
     */
    public function rewind(): void
    {
        throw new \RuntimeException('Cannot rewind an asynchronous event stream');
    }

    /**
     * @inheritDoc
     */
    public function isWritable(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function write(string $string): int
    {
        throw new \RuntimeException('Cannot write to a readable stream adapter');
    }

    /**
     * @inheritDoc
     */
    public function isReadable(): bool
    {
        return $this->hiblaStream->isReadable();
    }

    /**
     * @inheritDoc
     *
     * Simulates a synchronous read by suspending the current Fiber until
     * the requested amount of data arrives from the async stream.
     */
    public function read(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        if (\strlen($this->buffer) >= $length || $this->eof) {
            $chunk = substr($this->buffer, 0, $length);
            $this->buffer = substr($this->buffer, \strlen($chunk));

            return $chunk;
        }

        /** @var Promise<null> $promise */
        $promise = new Promise();

        /** @var callable|null $onData */
        $onData = null;

        /** @var callable|null $onEnd */
        $onEnd = null;

        /** @var callable|null $onError */
        $onError = null;

        $cleanup = function () use (&$onData, &$onEnd, &$onError): void {
            if ($onData !== null) {
                $this->hiblaStream->removeListener('data', $onData);
            }
            if ($onEnd !== null) {
                $this->hiblaStream->removeListener('end', $onEnd);
            }
            if ($onError !== null) {
                $this->hiblaStream->removeListener('error', $onError);
            }

            $onData = null;
            $onEnd = null;
            $onError = null;
        };

        $onData = function (string $chunk) use (&$promise, &$cleanup, $length): void {
            $this->buffer .= $chunk;
            if (\strlen($this->buffer) >= $length) {
                $cleanup();
                $this->hiblaStream->pause();
                $promise->resolve(null);
            }
        };

        $onEnd = function () use (&$promise, &$cleanup): void {
            $cleanup();
            $promise->resolve(null);
        };

        $onError = function (\Throwable $e) use (&$promise, &$cleanup): void {
            $cleanup();
            $promise->reject(new \RuntimeException('Stream read error: ' . $e->getMessage(), 0, $e));
        };

        $this->hiblaStream->on('data', $onData);
        $this->hiblaStream->on('end', $onEnd);
        $this->hiblaStream->on('error', $onError);

        $this->hiblaStream->resume();

        // Suspend the current Fiber until the buffer fills up or the stream ends
        await($promise);

        $chunk = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, \strlen($chunk));

        return $chunk;
    }

    /**
     * @inheritDoc
     *
     * Simulates a synchronous getContents by suspending the Fiber until
     * the stream fully drains.
     */
    public function getContents(): string
    {
        if ($this->eof) {
            $contents = $this->buffer;
            $this->buffer = '';

            return $contents;
        }

        /** @var Promise<string> $promise */
        $promise = new Promise();

        /** @var callable|null $onData */
        $onData = null;

        /** @var callable|null $onEnd */
        $onEnd = null;

        /** @var callable|null $onError */
        $onError = null;

        $cleanup = function () use (&$onData, &$onEnd, &$onError): void {
            if ($onData !== null) {
                $this->hiblaStream->removeListener('data', $onData);
            }
            if ($onEnd !== null) {
                $this->hiblaStream->removeListener('end', $onEnd);
            }
            if ($onError !== null) {
                $this->hiblaStream->removeListener('error', $onError);
            }

            $onData = null;
            $onEnd = null;
            $onError = null;
        };

        $onData = function (string $chunk): void {
            $this->buffer .= $chunk;
        };

        $onEnd = function () use (&$promise, &$cleanup): void {
            $cleanup();
            $contents = $this->buffer;
            $this->buffer = '';
            $promise->resolve($contents);
        };

        $onError = function (\Throwable $e) use (&$promise, &$cleanup): void {
            $cleanup();
            $promise->reject(new \RuntimeException('Stream read error: ' . $e->getMessage(), 0, $e));
        };

        $this->hiblaStream->on('data', $onData);
        $this->hiblaStream->on('end', $onEnd);
        $this->hiblaStream->on('error', $onError);

        $this->hiblaStream->resume();

        // Suspend the current Fiber until the EOF is reached
        return await($promise);
    }

    /**
     * @inheritDoc
     */
    public function getMetadata(?string $key = null)
    {
        return $key !== null ? null : [];
    }

    /**
     * @inheritDoc
     */
    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (\Throwable $e) {
            return '';
        }
    }
}
