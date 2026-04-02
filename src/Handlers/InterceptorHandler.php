<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Handlers;

use Hibla\HttpClient\Interfaces\RequestInterface;
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
 */
class InterceptorHandler
{
    /**
     * @param  RequestInterface $request
     * @param  array<callable(RequestInterface, callable): mixed> $interceptors
     * @param  callable(RequestInterface): PromiseInterface<Response> $executor
     * @return PromiseInterface<Response>
     */
    public function process(
        RequestInterface $request,
        array $interceptors,
        callable $executor,
    ): PromiseInterface {
        if ($interceptors === []) {
            return $executor($request);
        }

        $pipeline = array_reduce(
            array_reverse($interceptors),
            static function (callable $next, callable $interceptor): callable {
                return static function (RequestInterface $request) use ($next, $interceptor): PromiseInterface {
                    $result = $interceptor($request, $next);

                    if ($result === null) {
                        throw new \LogicException(
                            'Callback passed to intercept() must return a ' . PromiseInterface::class . ' or ' . Response::class . ', ' .
                            'got null/void. Did you forget to return $next($request) or the response?'
                        );
                    }

                    if ($result instanceof Response) {
                        return Promise::resolved($result);
                    }

                    if (! $result instanceof PromiseInterface) {
                        throw new \LogicException(\sprintf(
                            'Callback passed to intercept() must return a %s or %s, got %s. ' .
                            'Did you forget to return $next($request) or the response?',
                            PromiseInterface::class,
                            Response::class,
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
     */
    private static function resolveResponse(mixed $value): Response
    {
        if ($value === null) {
            throw new \LogicException(
                'The ' . PromiseInterface::class . ' returned by the callback passed to intercept() ' .
                'must resolve to a ' . Response::class . ' instance, got null/void.'
            );
        }

        if (! $value instanceof Response) {
            throw new \LogicException(sprintf(
                'The %s returned by the callback passed to intercept() ' .
                'must resolve to a %s instance, got %s.',
                PromiseInterface::class,
                Response::class,
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
