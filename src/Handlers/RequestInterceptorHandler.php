<?php

namespace Hibla\Http\Handlers;

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
     * @param array $interceptors Array of interceptor callbacks
     * @return PromiseInterface<Request> A promise that resolves with the processed request
     */
    public function processInterceptors(Request $request, array $interceptors): PromiseInterface
    {
        if (empty($interceptors)) {
            return $this->createResolvedPromise($request);
        }

        $promise = new CancellablePromise(function (callable $resolve, callable $reject) use ($request, $interceptors) {
            $this->processSequentially($request, $interceptors, $resolve, $reject);
        });

        return $promise;
    }

    /**
     * Process interceptors sequentially, handling both sync and async interceptors.
     */
    private function processSequentially(
        Request $request,
        array $interceptors,
        callable $resolve,
        callable $reject
    ): void {
        if (empty($interceptors)) {
            $resolve($request);
            return;
        }

        $interceptor = array_shift($interceptors);

        try {
            $result = $interceptor($request);

            if ($result instanceof PromiseInterface) {
                // Async interceptor - wait for it to complete before processing next
                $result->then(
                    function ($asyncRequest) use ($interceptors, $resolve, $reject) {
                        $this->processSequentially(
                            $asyncRequest,
                            $interceptors,
                            $resolve,
                            $reject
                        );
                    },
                    $reject
                );
            } else {
                // Sync interceptor - process immediately and continue
                $this->processSequentially(
                    $result,
                    $interceptors,
                    $resolve,
                    $reject
                );
            }
        } catch (\Throwable $e) {
            $reject($e);
        }
    }

    /**
     * Create a resolved promise with the given request.
     */
    private function createResolvedPromise(Request $request): PromiseInterface
    {
        $promise = new CancellablePromise(function (callable $resolve) use ($request) {
            $resolve($request);
        });

        return $promise;
    }
}