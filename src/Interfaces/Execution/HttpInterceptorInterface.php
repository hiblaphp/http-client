<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Execution;

use Hibla\HttpClient\Interfaces\PendingRequestInterface;
use Hibla\HttpClient\Interfaces\Response\EnhancedResponseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Contract for registering interceptors into the request pipeline.
 *
 * Three tiers of interception are available, in order of complexity:
 *
 *   Tier 1 — simple transforms with no async knowledge needed:
 *     interceptRequest(fn(PendingRequestInterface $r): PendingRequestInterface => ...)
 *     interceptResponse(fn(EnhancedResponseInterface $r): EnhancedResponseInterface => ...)
 *
 *   Tier 2 — full pipeline control where await() works freely:
 *     intercept(fn(PendingRequestInterface $r, callable $next): PromiseInterface => ...)
 *
 * Interceptors are executed in registration order. Each interceptor
 * wraps the next, forming a pipeline where the innermost layer is
 * the actual HTTP executor.
 *
 * All three tiers run inside a single master fiber per request, so
 * await() is safe to call inside any interceptor without creating
 * additional fiber overhead.
 *
 * Example:
 *   Http::intercept(function (PendingRequestInterface $request, callable $next) {
 *       $token = await(TokenStore::get('api_token'));
 *       $request = $request->withToken($token);
 *       $response = await($next($request));
 *       return $response;
 *   });
 */
interface HttpInterceptorInterface
{
    /**
     * Register a request interceptor.
     *
     * The callback receives the in-flight request just before it enters
     * the network layer and must return a PendingRequestInterface.
     * It may modify headers, auth, URI, or method.
     *
     * await() is safe to use inside the callback.
     *
     * @param  callable(PendingRequestInterface): PendingRequestInterface  $callback
     */
    public function interceptRequest(callable $callback): static;

    /**
     * Register a response interceptor.
     *
     * The callback receives the EnhancedResponseInterface after the network
     * call completes and must return an EnhancedResponseInterface.
     *
     * await() is safe to use inside the callback.
     *
     * @param  callable(EnhancedResponseInterface): EnhancedResponseInterface  $callback
     */
    public function interceptResponse(callable $callback): static;

    /**
     * Register a full pipeline interceptor.
     *
     * The callback receives the PendingRequestInterface and a $next callable.
     * Calling $next($request) executes the remainder of the pipeline and
     * returns a PromiseInterface<EnhancedResponseInterface>.
     *
     * This tier gives full control: the interceptor can short-circuit the
     * pipeline by not calling $next, fork it by calling $next multiple times,
     * or perform async work before and after the call.
     *
     * await() is safe to use inside the callback.
     *
     * @param  callable(PendingRequestInterface, callable): PromiseInterface<EnhancedResponseInterface>  $middleware
     */
    public function intercept(callable $middleware): static;
}
