<?php

declare(strict_types=1);

namespace Hibla\HttpClient\SSE;

use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Passed as the second argument to the onEvent callback.
 * Allows cancelling the SSE connection from within the callback
 * without needing to capture the promise reference externally.
 */
class SSEControl
{
    private bool $cancelled = false;

    /** @var PromiseInterface<SSEResponse>|null */
    private ?PromiseInterface $promise = null;

    /**
     * Wires the promise to this control after connect() creates it.
     *
     * @internal Called by SSEBuilder::connect() only.
     * @param PromiseInterface<SSEResponse> $promise
     */
    public function setPromise(PromiseInterface $promise): void
    {
        $this->promise = $promise;
    }

    /**
     * Cancel the SSE connection. Safe to call multiple times.
     */
    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;
        $this->promise?->cancel();
    }

    /**
     * Whether cancel() has been called.
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
