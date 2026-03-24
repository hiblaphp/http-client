<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces;

use Hibla\HttpClient\Request;
use Hibla\HttpClient\Response;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * HTTP interceptor interface.
 *
 * Three tiers of interceptor registration:
 *
 * Tier 1 — simple transforms, no async knowledge needed:
 *   interceptRequest(fn(Request $r): Request => ...)
 *   interceptResponse(fn(Response $r): Response => ...)
 *
 * Tier 2 — full pipeline control, await() works freely:
 *   intercept(fn(Request $r, callable $next): PromiseInterface => ...)
 */
interface HttpInterceptorInterface
{
    /**
     * Add a request interceptor.
     *
     * The callback receives the Request before sending and MUST return
     * a Request. await() works freely inside — the master fiber handles it.
     *
     * @param callable(Request): Request $callback
     */
    public function interceptRequest(callable $callback): self;

    /**
     * Add a response interceptor.
     *
     * The callback receives the Response after sending and MUST return
     * a Response. await() works freely inside — the master fiber handles it.
     *
     * @param callable(Response): Response $callback
     */
    public function interceptResponse(callable $callback): self;

    /**
     * Add a full pipeline interceptor with access to both request and response.
     *
     * The callback receives the Request and a $next callable. Calling $next
     * executes the remainder of the pipeline. await() works freely inside.
     *
     * Example:
     *   Http::intercept(function (Request $request, callable $next) {
     *       $token = await(TokenStore::get('api_token'));
     *       $request = $request->withHeader('Authorization', "Bearer {$token}");
     *       $response = await($next($request));
     *       return $response;
     *   });
     *
     * @param callable(Request, callable): PromiseInterface<Response> $middleware
     */
    public function intercept(callable $middleware): self;
}