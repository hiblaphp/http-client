<?php

declare(strict_types=1);

namespace Hibla\HttpClient\SSE;

use Hibla\HttpClient\Interfaces\SSE\SSEControlInterface;
use Hibla\HttpClient\Interfaces\SSEResponseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

class SSEControl implements SSEControlInterface
{
    private bool $cancelled = false;

    /**
     *  @var PromiseInterface<SSEResponseInterface>|null
     */
    private ?PromiseInterface $promise = null;

    /**
     * Wires the promise to this control after connect() creates it.
     *
     * @internal Called by SSEBuilder::connect() only.
     *
     * @param PromiseInterface<SSEResponseInterface> $promise
     */
    public function setPromise(PromiseInterface $promise): void
    {
        $this->promise = $promise;
    }

    /**
     *  @inheritDoc
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
     *  @inheritDoc
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
