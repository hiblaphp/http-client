<?php

namespace Hibla\Http\Handlers;

use Hibla\Http\Exceptions\RequestException;
use Hibla\Http\Request;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Handles sequential processing of request interceptors.
 */
class RequestInterceptorHandler
{
    /**
     * Process request interceptors sequentially, handling both sync and async interceptors.
     *
     * @param Request $request The initial request
     * @param array<callable(Request): (Request|PromiseInterface<Request>)> $interceptors Array of interceptor callbacks
     * @return PromiseInterface<Request> A promise that resolves with the processed request
     */
    public function processInterceptors(Request $request, array $interceptors): PromiseInterface
    {
        if ($interceptors === []) {
            return $this->createResolvedPromise($request);
        }

        /** @var CancellablePromise<Request> $promise */
        $promise = new CancellablePromise(function (callable $resolve, callable $reject) use ($request, $interceptors) {
            $this->processSequentially($request, $interceptors, $resolve, $reject);
        });

        return $promise;
    }

    /**
     * Process interceptors sequentially, handling both sync and async interceptors.
     * @param array<callable(Request): (Request|PromiseInterface<Request>)> $interceptors
     */
    private function processSequentially(
        Request $request,
        array $interceptors,
        callable $resolve,
        callable $reject
    ): void {
        if ($interceptors === []) {
            $resolve($request);
            return;
        }

        $interceptor = array_shift($interceptors);

        try {
            $result = $interceptor($request);

            if ($result instanceof PromiseInterface) {
                // Async interceptor - wait for it to complete before processing next
                $result->then(
                    function (Request $asyncRequest) use ($interceptors, $resolve, $reject) {
                        $this->processSequentially(
                            $asyncRequest,
                            $interceptors,
                            $resolve,
                            $reject
                        );
                    },
                    $reject
                );
            } elseif ($result instanceof Request) {
                // Sync interceptor - process immediately and continue
                $this->processSequentially(
                    $result,
                    $interceptors,
                    $resolve,
                    $reject
                );
            } else {
                throw new RequestException('InterceptRequest() must return a Request or a PromiseInterface that resolves with a Request.');
            }
        } catch (\Throwable $e) {
            $reject($e);
        }
    }

    /**
     * Create a resolved promise with the given request.
     * @return PromiseInterface<Request>
     */
    private function createResolvedPromise(Request $request): PromiseInterface
    {
        /** @var CancellablePromise<Request> $promise */
        $promise = new CancellablePromise(function (callable $resolve) use ($request) {
            $resolve($request);
        });

        return $promise;
    }
}