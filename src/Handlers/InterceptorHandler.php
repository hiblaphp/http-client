<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Handlers;

use Hibla\HttpClient\Request;
use Hibla\HttpClient\Response;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

use function Hibla\async;
use function Hibla\await;

/**
 * Handles the unified interceptor pipeline.
 *
 * All interceptors run inside a single master fiber per request,
 * meaning await() works freely inside any interceptor without
 * creating additional fiber overhead.
 *
 * Three ways to register interceptors:
 *
 * // Tier 1 - simple request transform:
 * Http::interceptRequest(fn(Request $r) => $r->withHeader('X-App', 'my-app'));
 *
 * // Tier 1 - simple response transform:
 * Http::interceptResponse(fn(Response $r) => $r);
 *
 * // Tier 2 - full pipeline control with await() support:
 * Http::intercept(function (Request $request, callable $next) {
 *     $token = await(TokenStore::get('api_token'));
 *     $request = $request->withHeader('Authorization', "Bearer {$token}");
 *     $response = await($next($request));
 *     return $response;
 * });
 */
class InterceptorHandler
{
    /**
     * Process the interceptor pipeline inside a single master fiber.
     *
     * @param array<callable(Request, callable): PromiseInterface<Response>> $interceptors
     * @param callable(Request): PromiseInterface<Response> $executor
     * @return PromiseInterface<Response>
     */
    public function process(
        Request $request,
        array $interceptors,
        callable $executor
    ): PromiseInterface {
        if ($interceptors === []) {
            return $executor($request);
        }

        $pipeline = array_reduce(
            array_reverse($interceptors),
            function (callable $next, callable $interceptor): callable {
                return function (Request $request) use ($next, $interceptor): PromiseInterface {
                    $result = $interceptor($request, $next);

                    if ($result instanceof PromiseInterface) {
                        return $result;
                    }

                    return Promise::resolved($result);
                };
            },
            $executor
        );

        return async(static function () use ($pipeline, $request) {
            return await($pipeline($request));
        });
    }
}
