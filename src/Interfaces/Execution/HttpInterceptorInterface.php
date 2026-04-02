<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Execution;

use Hibla\HttpClient\Interfaces\ResponseInterface;
use Hibla\HttpClient\Interfaces\RequestInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Contract for registering interceptors into the request pipeline.
 *
 * Three tiers of interception are available, in order of complexity:
 *
 *   Tier 1 — simple transforms with no async knowledge needed:
 *     interceptRequest(fn(RequestInterface $r): RequestInterface => ...)
 *     interceptResponse(fn(ResponseInterface $r): ResponseInterface => ...)
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
 * Example:
 *   Http::intercept(function (RequestInterface $request, callable $next) {
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
     * The callback may return a plain RequestInterface or a
     * PromiseInterface that resolves to one, allowing async work
     * (e.g. token refresh) before the request is dispatched.
     *
     * await() is safe to use inside the callback.
     *
     * @param  callable(RequestInterface): (RequestInterface|PromiseInterface<RequestInterface>) $callback
     */
    public function interceptRequest(callable $callback): static;

    /**
     * Register a response interceptor.
     *
     * The callback may return a plain ResponseInterface or a
     * PromiseInterface that resolves to one, allowing async post-processing.
     *
     * await() is safe to use inside the callback.
     *
     * @param  callable(ResponseInterface): (ResponseInterface|PromiseInterface<ResponseInterface>) $callback
     */
    public function interceptResponse(callable $callback): static;
    
    /**
     * Register a full pipeline interceptor.
     *
     * The callback receives the RequestInterface and a $next callable.
     * Calling $next($request) executes the remainder of the pipeline and
     * returns a PromiseInterface<ResponseInterface>.
     *
     * This tier gives full control: the interceptor can short-circuit the
     * pipeline by not calling $next, fork it by calling $next multiple times,
     * or perform async work before and after the call.
     *
     * await() is safe to use inside the callback.
     *
     * @param  callable(RequestInterface, callable): PromiseInterface<ResponseInterface> $middleware
     */
    public function intercept(callable $middleware): static;
}
