<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Execution;

use Hibla\HttpClient\Interfaces\Response\EnhancedResponseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Contract for registering interceptors into the request pipeline.
 *
 * Three tiers of interception are available, in order of complexity:
 *
 *   Tier 1 — simple transforms with no async knowledge needed:
 *     interceptRequest(fn(RequestInterface $r): RequestInterface => ...)
 *     interceptResponse(fn(EnhancedResponseInterface $r): EnhancedResponseInterface => ...)
 *
 *   Tier 2 — full pipeline control where await() works freely:
 *     intercept(fn(RequestInterface $r, callable $next): PromiseInterface => ...)
 *
 * Interceptors are executed in registration order. Each interceptor
 * wraps the next, forming a pipeline where the innermost layer is
 * the actual HTTP executor.
 *
 * All three tiers run inside a single master fiber per request, so
 * await() is safe to call inside any interceptor without creating
 * additional fiber overhead.
 *
 * The asymmetry between request and response types is intentional:
 *
 *   - The request flowing through the pipeline is a PSR-7 immutable
 *     message (RequestInterface) — not the fluent builder. By the time
 *     intercept() runs, building is complete.
 *
 *   - The response flowing through the pipeline is always an
 *     EnhancedResponseInterface, giving interceptors access to the
 *     convenience methods (json(), body(), successful(), etc.) without
 *     requiring a cast.
 */
interface HttpInterceptorInterface
{
    /**
     * Register a request interceptor.
     *
     * The callback receives the fully built PSR-7 request just before
     * it enters the network layer and must return a RequestInterface.
     * It may modify, replace, or enrich the request (e.g. inject headers,
     * rewrite the URL, or attach a signed payload).
     *
     * await() is safe to use inside the callback.
     *
     * @param  callable(RequestInterface): RequestInterface  $callback
     */
    public function interceptRequest(callable $callback): static;

    /**
     * Register a response interceptor.
     *
     * The callback receives the EnhancedResponseInterface after the network
     * call completes and must return an EnhancedResponseInterface. It may
     * inspect, transform, or replace the response (e.g. unwrap an envelope,
     * throw on a domain error, or inject synthetic headers).
     *
     * await() is safe to use inside the callback.
     *
     * @param  callable(EnhancedResponseInterface): EnhancedResponseInterface  $callback
     */
    public function interceptResponse(callable $callback): static;

    /**
     * Register a full pipeline interceptor.
     *
     * The callback receives the RequestInterface and a $next callable.
     * Calling $next($request) executes the remainder of the pipeline
     * and returns a PromiseInterface<EnhancedResponseInterface>.
     *
     * This tier gives full control: the interceptor can short-circuit
     * the pipeline by not calling $next, fork it by calling $next
     * multiple times, or perform async work before and after the call.
     *
     * Example:
     *   Http::intercept(function (RequestInterface $request, callable $next) {
     *       $token = await(TokenStore::get('api_token'));
     *       $request = $request->withHeader('Authorization', "Bearer {$token}");
     *       $response = await($next($request));
     *       return $response;
     *   });
     *
     * @param  callable(RequestInterface, callable): PromiseInterface<EnhancedResponseInterface>  $middleware
     */
    public function intercept(callable $middleware): static;
}