<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces;

use Hibla\HttpClient\Interfaces\Builder\ConfiguresAuthInterface;
use Hibla\HttpClient\Interfaces\Builder\ConfiguresBodyInterface;
use Hibla\HttpClient\Interfaces\Builder\ConfiguresCookiesInterface;
use Hibla\HttpClient\Interfaces\Builder\ConfiguresHeadersInterface;
use Psr\Http\Message\RequestInterface as Psr7RequestInterface;

/**
 * Represents an in-flight request as seen by the interceptor pipeline.
 *
 * Intentionally narrower than HttpClientInterface — transport config
 * (timeout, retry, proxy, curl options) is fixed by the time the
 * pipeline runs and is not exposed here.
 *
 * What interceptors can legitimately do:
 *   - Modify headers and auth          (ConfiguresHeadersInterface, ConfiguresAuthInterface)
 *   - Modify or replace the body       (ConfiguresBodyInterface)
 *   - Inject or manage cookies         (ConfiguresCookiesInterface)
 *   - Read/rewrite the URI and method  (RequestInterface)
 *
 * Example:
 *   Http::withInterceptor(function (RequestInterface $request, callable $next) {
 *       $token = await(TokenStore::get('api_token'));
 *       $request = $request->withToken($token)
 *                          ->withCookie('session', $sessionId)
 *                          ->withHeader('X-Request-Id', uniqid());
 *       return await($next($request));
 *   });
 */
interface RequestInterface extends
    Psr7RequestInterface,
    ConfiguresHeadersInterface,
    ConfiguresAuthInterface,
    ConfiguresBodyInterface,
    ConfiguresCookiesInterface
{
}
