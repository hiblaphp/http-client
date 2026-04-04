<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Handlers;

use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\Handlers\ReadAllHandler;
use Hibla\Stream\Handlers\ReadLineHandler;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use RuntimeException;

/**
 * Manages the internal state, buffer, and promise queues for an HttpStream.
 * 
 * Designed to bypass ext-uv file-descriptor limitations by relying 
 * entirely on "Push" updates from cURL's write callback.
 */
class HttpStreamStateHandler
{
    /**
     *  @var resource|null 
     */
    private $resource;

    /**
     *  @var array<int, array{promise: Promise<string|null>, length: int}> 
     */
    private array $readQueue = [];

    private bool $eof = false;

    private bool $closed = false;

    private string $prependBuffer = '';

    private int $readPosition = 0;

    private ReadLineHandler $lineHandler;

    private ReadAllHandler $allHandler;

    public function __construct(&$resource)
    {
        $this->resource = &$resource;

        $this->lineHandler = new ReadLineHandler(
            fn(?int $length) => $this->readAsync($length),
            function (string $data) {
                $this->prependBuffer = $data . $this->prependBuffer;
            }
        );

        $this->allHandler = new ReadAllHandler(
            65536,
            fn(?int $length) => $this->readAsync($length)
        );
    }

    public function readAsync(?int $length = null): PromiseInterface
    {
        $promise = new Promise();
        if ($this->closed) {
            $promise->reject(new RuntimeException('Stream is closed'));
            return $promise;
        }

        $this->readQueue[] = ['promise' => $promise, 'length' => $length ?? 65536];
        $this->pump();

        return $promise;
    }

    public function readLineAsync(?int $maxLength = null): PromiseInterface
    {
        if ($this->closed) {
            return Promise::rejected(new RuntimeException('Stream is closed'));
        }

        if ($this->eof && $this->prependBuffer === '') {
            if ($this->resource !== null) {
                fseek($this->resource, $this->readPosition, SEEK_SET);
                if (feof($this->resource)) return Promise::resolved(null);
            }
        }

        $maxLen = $maxLength ?? 65536;
        $line = $this->lineHandler->findLineInBuffer($this->prependBuffer, $maxLen);
        if ($line !== null) return Promise::resolved($line);

        return $this->lineHandler->readLineFromStream($this->prependBuffer, $maxLen);
    }

    public function readAllAsync(int $maxLength = 1048576): PromiseInterface
    {
        if ($this->closed) return Promise::rejected(new RuntimeException('Stream is closed'));
        $buffer = $this->prependBuffer;
        $this->prependBuffer = '';
        return $this->allHandler->readAll($buffer, $maxLength);
    }

    public function pipeAsync(WritableStreamInterface $destination, array $options = []): PromiseInterface
    {
        $promise = new Promise();
        $total = 0;
        $endDest = $options['end'] ?? true;

        $pumpPipe = function () use ($destination, $promise, $endDest, &$total, &$pumpPipe) {
            if ($this->closed) return;
            $this->readAsync()->then(function ($chunk) use ($destination, $promise, $endDest, &$total, &$pumpPipe) {
                if ($chunk === null) {
                    if ($endDest) $destination->end();
                    $promise->resolve($total);
                    return;
                }
                $total += \strlen($chunk);
                if ($destination->write($chunk)) {
                    $pumpPipe();
                } else {
                    $destination->once('drain', $pumpPipe);
                }
            })->catch(fn($e) => $promise->reject($e));
        };

        $pumpPipe();
        return $promise;
    }

    /**
     * "Push" data directly into the stream buffer.
     */
    public function writeToBuffer(string $data): void
    {
        if ($this->closed || $this->resource === null) {
            return;
        }

        $current = ftell($this->resource);
        fseek($this->resource, 0, SEEK_END);
        fwrite($this->resource, $data);
        fseek($this->resource, $current, SEEK_SET);

        $this->pump();
    }

    /**
     * Mark the stream as finished.
     */
    public function markEof(): void
    {
        $this->eof = true;
        $this->pump();
    }

    public function clearBuffer(): void
    {
        $this->readPosition = $this->resource !== null ? ftell($this->resource) : 0;
        $this->prependBuffer = '';
    }

    public function isEof(): bool
    {
        if ($this->resource === null) return true;

        $fstat = fstat($this->resource);
        $size = $fstat['size'] ?? 0;

        return $this->eof && empty($this->prependBuffer) && ($this->readPosition >= $size);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        while ($req = array_shift($this->readQueue)) {
            $req['promise']->reject(new RuntimeException('Stream closed'));
        }
    }

    private function pump(): void
    {
        if (empty($this->readQueue) || $this->resource === null) {
            return;
        }

        fseek($this->resource, $this->readPosition, SEEK_SET);
        $current = ftell($this->resource);
        fseek($this->resource, 0, SEEK_END);
        $end = ftell($this->resource);
        fseek($this->resource, $current, SEEK_SET);

        $available = $end - $current;

        if ($available > 0 || $this->prependBuffer !== '') {
            $req = array_shift($this->readQueue);
            $length = $req['length'];
            $chunk = '';

            if ($this->prependBuffer !== '') {
                $chunk = substr($this->prependBuffer, 0, $length);
                $this->prependBuffer = substr($this->prependBuffer, strlen($chunk));
                $length -= \strlen($chunk);
            }

            if ($length > 0 && $available > 0) {
                $fileChunk = fread($this->resource, $length);
                if ($fileChunk !== false) {
                    $chunk .= $fileChunk;
                    $this->readPosition += strlen($fileChunk);
                }
            }

            $req['promise']->resolve($chunk);

            if (!empty($this->readQueue)) {
                Loop::microTask(fn() => $this->pump());
            }
        } elseif ($this->eof) {
            while ($req = array_shift($this->readQueue)) {
                $req['promise']->resolve(null);
            }
        }
    }
}
