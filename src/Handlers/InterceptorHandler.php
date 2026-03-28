<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Handlers;

use function Hibla\async;
use function Hibla\await;

use Hibla\HttpClient\Interfaces\PendingRequestInterface;

use Hibla\HttpClient\Response;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Handles the unified interceptor pipeline.
 *
 * All interceptors run inside a single master fiber per request,
 * meaning await() works freely inside any interceptor without
 * creating additional fiber overhead.
 *
 * Three ways to register interceptors:
 *
 *   // Tier 1 — simple request transform:
 *   Http::interceptRequest(fn(PendingRequestInterface $r) =>
 *       $r->withHeader('X-App', 'my-app')
 *   );
 *
 *   // Tier 1 — simple response transform:
 *   Http::interceptResponse(fn(Response $r) => $r);
 *
 *   // Tier 2 — full pipeline control with await() support:
 *   Http::intercept(function (PendingRequestInterface $request, callable $next) {
 *       $token = await(TokenStore::get('api_token'));
 *       $request = $request->withToken($token);
 *       $response = await($next($request));
 *       return $response;
 *   });
 */
class InterceptorHandler
{
    /**
     * @param  PendingRequestInterface $request
     * @param  array<callable(PendingRequestInterface, callable): mixed> $interceptors
     * @param  callable(PendingRequestInterface): PromiseInterface<Response> $executor
     * @return PromiseInterface<Response>
     */
    public function process(
        PendingRequestInterface $request,
        array $interceptors,
        callable $executor,
    ): PromiseInterface {
        if ($interceptors === []) {
            return $executor($request);
        }

        $pipeline = array_reduce(
            array_reverse($interceptors),
            static function (callable $next, callable $interceptor): callable {
                return static function (PendingRequestInterface $request) use ($next, $interceptor): PromiseInterface {
                    $result = $interceptor($request, $next);

                    if ($result === null) {
                        throw new \LogicException(
                            'Callback passed to intercept() must return a ' . PromiseInterface::class . ', ' .
                            'got null/void. Did you forget to return $next($request) or the response?'
                        );
                    }

                    if (! $result instanceof PromiseInterface) {
                        throw new \LogicException(sprintf(
                            'Callback passed to intercept() must return a %s, got %s. ' .
                            'Did you forget to return $next($request) or the response?',
                            PromiseInterface::class,
                            get_debug_type($result),
                        ));
                    }

                    return $result->then(
                        static fn (mixed $resolved): Response => self::resolveResponse($resolved)
                    );
                };
            },
            $executor,
        );

        return async(static function () use ($pipeline, $request): mixed {
            return await($pipeline($request));
        });
    }

    /**
     * Assert the resolved pipeline value is a Response.
     *
     * @throws \LogicException When the resolved value is not a Response.
     */
    private static function resolveResponse(mixed $value): Response
    {
        if ($value === null) {
            throw new \LogicException(
                'The ' . PromiseInterface::class . ' returned by the callback passed to intercept() ' .
                'must resolve to a ' . Response::class . ' instance, got null/void. ' .
                'Did you forget to return $next($request) or the response?'
            );
        }

        if (! $value instanceof Response) {
            throw new \LogicException(sprintf(
                'The %s returned by the callback passed to intercept() ' .
                'must resolve to a %s instance, got %s. ' .
                'Did you forget to return $next($request) or the response?',
                PromiseInterface::class,
                Response::class,
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
