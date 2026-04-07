<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Handlers;

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Exceptions\HttpStreamException;
use Hibla\Promise\Promise;
use RuntimeException;

/**
 * Manages the internal state, buffer, and promise fulfilling for an HttpStream.
 *
 * This class is internal and should not be used directly by application code.
 * It focuses strictly on the state machine logic required to support push-based
 * updates from cURL.
 *
 * @internal
 */
class HttpStreamStateHandler
{
    /** @var resource|null */
    private $resource;

    /**
     *  @var array<int, array{promise: Promise<string|null>, length: int}>
     */
    private array $readQueue = [];

    private bool $eof = false;

    private bool $closed = false;

    private string $prependBuffer = '';

    private int $readPosition = 0;

    /**
     * @param mixed $resource
     */
    public function __construct(&$resource)
    {
        /** @var resource|null $resource */
        $this->resource = &$resource;
    }

    /**
     * @param int $length
     * @param Promise<string|null> $promise
     */
    public function enqueueRead(int $length, Promise $promise): void
    {
        if ($this->closed) {
            $promise->reject(new RuntimeException('Stream is closed'));

            return;
        }

        $this->readQueue[] = ['promise' => $promise, 'length' => $length];

        $promise->onCancel(fn () => $this->dequeueRead($promise));

        $this->pump();
    }

    /**
     * @param Promise<string|null> $promise
     */
    public function dequeueRead(Promise $promise): void
    {
        foreach ($this->readQueue as $index => $item) {
            if ($item['promise'] === $promise) {
                unset($this->readQueue[$index]);
                $this->readQueue = array_values($this->readQueue);

                break;
            }
        }
    }

    public function writeToBuffer(string $data): void
    {
        if ($this->closed || $this->resource === null) {
            return;
        }

        $current = ftell($this->resource);
        $current = $current === false ? 0 : $current;

        fseek($this->resource, 0, SEEK_END);
        fwrite($this->resource, $data);
        fseek($this->resource, $current, SEEK_SET);

        $this->pump();
    }

    public function markEof(): void
    {
        $this->eof = true;
        $this->pump();
    }

    public function clearBuffers(): void
    {
        $pos = $this->resource !== null ? ftell($this->resource) : 0;
        $this->readPosition = $pos === false ? 0 : $pos;
        $this->prependBuffer = '';
    }

    public function isEof(): bool
    {
        if ($this->resource === null) {
            return true;
        }
        $fstat = fstat($this->resource);
        $size = $fstat['size'] ?? 0;

        return $this->eof && $this->prependBuffer === '' && ($this->readPosition >= $size);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        while ($req = array_shift($this->readQueue)) {
            if (! $req['promise']->isCancelled()) {
                $req['promise']->reject(new HttpStreamException('Stream closed unexpectedly'));
            }
        }
    }

    public function pump(): void
    {
        if (\count($this->readQueue) === 0 || $this->resource === null) {
            return;
        }

        fseek($this->resource, $this->readPosition, SEEK_SET);
        $current = ftell($this->resource);
        fseek($this->resource, 0, SEEK_END);
        $end = ftell($this->resource);

        if ($current === false || $end === false) {
            return;
        }

        fseek($this->resource, $current, SEEK_SET);

        $available = $end - $current;
        $totalAvailable = \strlen($this->prependBuffer) + $available;

        if ($totalAvailable > 0) {
            $next = $this->readQueue[0];

            if ($available === 0 && $totalAvailable < $next['length'] && ! $this->eof) {
                return;
            }

            $req = array_shift($this->readQueue);
            if ($req === null) {
                return;
            }

            if ($req['promise']->isCancelled()) {
                Loop::microTask($this->pump(...));

                return;
            }

            $length = $req['length'];
            $chunk = '';

            if ($this->prependBuffer !== '') {
                $chunk = substr($this->prependBuffer, 0, $length);
                $this->prependBuffer = substr($this->prependBuffer, strlen($chunk));
                $length -= strlen($chunk);
            }

            if ($length > 0 && $available > 0) {
                $fileChunk = fread($this->resource, $length);
                if ($fileChunk !== false) {
                    $chunk .= $fileChunk;
                    $this->readPosition += strlen($fileChunk);
                }
            }

            $req['promise']->resolve($chunk);

            if (\count($this->readQueue) > 0) {
                Loop::microTask($this->pump(...));
            }
        } elseif ($this->eof) {
            while ($req = array_shift($this->readQueue)) {
                if (! $req['promise']->isCancelled()) {
                    $req['promise']->resolve(null);
                }
            }
        }
    }

    public function getPrependBuffer(): string
    {
        return $this->prependBuffer;
    }

    public function setPrependBuffer(string $data): void
    {
        $this->prependBuffer = $data;
    }

    public function getReadPosition(): int
    {
        return $this->readPosition;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}