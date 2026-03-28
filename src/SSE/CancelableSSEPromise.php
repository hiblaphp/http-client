<?php

declare(strict_types=1);

namespace Hibla\HttpClient\SSE;

use Hibla\Promise\Exceptions\AggregateErrorException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

/**
 * A cancelable promise wrapper for SSE connections.
 *
 * This wrapper ensures that calling cancel() will properly close the SSE response
 * and clean up the underlying connection, whether it's a real SSE stream or a mock.
 *
 * @template-extends Promise<SSEResponse>
 * @implements PromiseInterface<SSEResponse>
 */
class CancelableSSEPromise extends Promise implements PromiseInterface
{
    /** @var PromiseInterface<SSEResponse> */
    private PromiseInterface $innerPromise;

    private ?SSEResponse $sseResponse = null;

    /** @var list<callable> */
    private array $cancelCallbacks = [];

    /**
     * @param PromiseInterface<SSEResponse> $innerPromise The wrapped promise
     */
    public function __construct(PromiseInterface $innerPromise)
    {
        parent::__construct();
        $this->innerPromise = $innerPromise;

        $innerPromise->then(
            function ($response) {
                $this->sseResponse = $response;
                $this->resolve($response);
            },
            function ($error) {
                $this->reject($error);
            }
        );
    }

    /**
     * Cancels the SSE connection and cleans up resources.
     *
     * Collects all exceptions thrown during the cancellation process (callbacks,
     * stream closing, inner promise cancellation) and throws an AggregateErrorException
     * if multiple errors occur.
     *
     * @throws \Throwable|AggregateErrorException
     */
    public function cancel(): void
    {
        if ($this->isCancelled()) {
            return;
        }

        $cancelExceptions = [];

        foreach ($this->cancelCallbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                $cancelExceptions[] = $e;
            }
        }

        $this->cancelCallbacks = [];

        if ($this->sseResponse instanceof SSEResponse) {
            try {
                $this->sseResponse->close();
            } catch (\Throwable $e) {
                $cancelExceptions[] = $e;
            }
        }

        try {
            $this->innerPromise->cancel();
        } catch (\Throwable $e) {
            $cancelExceptions[] = $e;
        }

        try {
            parent::cancel();
        } catch (\Throwable $e) {
            $cancelExceptions[] = $e;
        }

        if (\count($cancelExceptions) === 1) {
            throw $cancelExceptions[0];
        } elseif (\count($cancelExceptions) > 1) {
            $errorMessages = [];
            foreach ($cancelExceptions as $index => $exception) {
                $errorMessages[] = \sprintf(
                    '#%d: [%s] %s in %s:%d',
                    $index + 1,
                    \get_class($exception),
                    $exception->getMessage(),
                    $exception->getFile(),
                    $exception->getLine()
                );
            }

            $detailedMessage = \sprintf(
                "SSE Promise cancellation failed with %d error(s):\n%s",
                \count($cancelExceptions),
                implode("\n", $errorMessages)
            );

            throw new AggregateErrorException(
                $cancelExceptions,
                $detailedMessage
            );
        }
    }

    /**
     * Registers a callback to be called when the promise is cancelled.
     *
     * @return $this
     */
    public function onCancel(callable $callback): self
    {
        if ($this->isCancelled()) {
            try {
                $callback();
            } catch (\Throwable $e) {
                throw $e;
            }

            return $this;
        }

        $this->cancelCallbacks[] = $callback;

        return $this;
    }
}
