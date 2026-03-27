<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Handler;

use Hibla\HttpClient\Response;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Contract for executing a single HTTP request without any
 * additional retry, caching, or interceptor logic.
 *
 * This is the innermost layer of the handler stack. All higher-level
 * handlers (retry, interceptor) ultimately delegate to an implementation
 * of this interface to perform the actual network call.
 *
 * Errors are categorised into typed exceptions so callers can apply
 * different recovery strategies:
 *
 *   - TimeoutException      — operation or connection timeout exceeded
 *   - NetworkException      — transport failure (DNS, SSL, refused, unreachable)
 *
 * HTTP error status codes (4xx, 5xx) are NOT thrown here; they are
 * surfaced as resolved Response objects so callers can inspect them.
 */
interface RequestExecutorHandlerInterface
{
    /**
     * Execute a single HTTP request and return a promise that resolves
     * to a Response on any completed HTTP exchange, or rejects with a
     * NetworkException or TimeoutException on transport failure.
     *
     * @param  string                    $url      The fully resolved target URL.
     * @param  array<int|string, mixed>  $options  Transport-specific options produced
     *                                             by TransportOptionsBuilderInterface::build().
     * @return PromiseInterface<Response>
     */
    public function execute(string $url, array $options): PromiseInterface;
}