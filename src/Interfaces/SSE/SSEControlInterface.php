<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\SSE;

/**
 * Passed as the second argument to the onEvent callback.
 * Allows cancelling the SSE connection from within the callback
 * without needing to capture the promise reference externally.
 */
interface SSEControlInterface
{
    /**
     * Cancel the SSE connection. Safe to call multiple times.
     */
    public function cancel(): void;

    /**
     * Whether cancel() has been called.
     */
    public function isCancelled(): bool;
}