<?php

namespace Hibla\Http\Handlers;

use Hibla\Http\Response;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Handles sequential processing of response interceptors.
 */
class ResponseInterceptorHandler
{
    /**
     * Process response interceptors sequentially.
     *
     * @param Response $response The initial response
     * @param array $interceptors Array of interceptor callbacks
     * @return PromiseInterface<Response> A promise that resolves with the processed response
     */
    public function processInterceptors(Response $response, array $interceptors): PromiseInterface
    {
        if (empty($interceptors)) {
            return $this->createResolvedPromise($response);
        }

        $promise = new CancellablePromise(function (callable $resolve, callable $reject) use ($response, $interceptors) {
            $this->processSequentially($response, $interceptors, $resolve, $reject);
        });

        return $promise;
    }

    /**
     * Process response interceptors sequentially, handling both sync and async interceptors.
     */
    private function processSequentially(
        Response $response,
        array $interceptors,
        callable $resolve,
        callable $reject
    ): void {
        if (empty($interceptors)) {
            $resolve($response);
            return;
        }

        $interceptor = array_shift($interceptors);

        try {
            $result = $interceptor($response);

            if ($result instanceof PromiseInterface) {
                // Async interceptor - wait for it to complete before processing next
                $result->then(
                    function ($asyncResponse) use ($interceptors, $resolve, $reject) {
                        $this->processSequentially(
                            $asyncResponse,
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
     * Create a resolved promise with the given response.
     */
    private function createResolvedPromise(Response $response): PromiseInterface
    {
        $promise = new CancellablePromise(function (callable $resolve) use ($response) {
            $resolve($response);
        });

        return $promise;
    }
}