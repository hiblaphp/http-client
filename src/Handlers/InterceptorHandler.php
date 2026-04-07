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
 */
class InterceptorHandler
{
    /**
     * @template TResult
     *
     * @param  RequestInterface $request
     * @param  array<callable(RequestInterface, callable): mixed> $interceptors
     * @param  callable(RequestInterface): PromiseInterface<TResult> $executor
     * @param  bool $requireResponse
     * @return PromiseInterface<TResult>
     */
    public function process(
        RequestInterface $request,
        array $interceptors,
        callable $executor,
        bool $requireResponse = true
    ): PromiseInterface {
        if ($interceptors === []) {
            return $executor($request);
        }

        $pipeline = array_reduce(
            array_reverse($interceptors),
            static function (callable $next, callable $interceptor) use ($requireResponse): callable {
                return static function (RequestInterface $request) use ($next, $interceptor, $requireResponse): PromiseInterface {
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

                    $mapped = $result->then(
                        static fn($resolved): Response|array => self::resolveResult($resolved, $requireResponse)
                    );

                    $mapped->onCancel($result->cancelChain(...));

                    return $mapped;
                };
            },
            $executor,
        );

        $state = new class {
            /** @var PromiseInterface<mixed>|null */
            public PromiseInterface|null $innerPromise = null;
        };

        /** @var PromiseInterface<TResult> $outerPromise */
        $outerPromise = async(static function () use ($pipeline, $request, $state): mixed {
            $innerPromise = $pipeline($request);
            $state->innerPromise = $innerPromise;

            return await($state->innerPromise);
        });

        $outerPromise->onCancel(function () use ($state) {
            if ($state->innerPromise instanceof PromiseInterface && ! $state->innerPromise->isSettled()) {
                $state->innerPromise->cancelChain();
            }
        });

        return $outerPromise;
    }

    /**
     * Assert the resolved pipeline value matches the expected type.
     *
     * @return Response|array<mixed>
     */
    private static function resolveResult(mixed $value, bool $requireResponse): Response|array
    {
        if ($requireResponse) {
            if ($value === null) {
                throw new \LogicException(
                    'The ' . PromiseInterface::class . ' returned by the callback passed to intercept() ' .
                        'must resolve to a ' . Response::class . ' instance, got null/void.'
                );
            }

            if (! $value instanceof Response) {
                throw new \LogicException(\sprintf(
                    'The %s returned by the callback passed to intercept() ' .
                        'must resolve to a %s instance, got %s.',
                    PromiseInterface::class,
                    Response::class,
                    get_debug_type($value),
                ));
            }

            return $value;
        }

        if (! is_array($value)) {
            throw new \LogicException(\sprintf(
                'The %s returned by the callback passed to intercept() for a download request ' .
                    'must resolve to an array, got %s.',
                PromiseInterface::class,
                get_debug_type($value),
            ));
        }

        return $value;
    }
}