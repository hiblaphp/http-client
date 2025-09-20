<?php

namespace Hibla\Http\Traits;

use Hibla\Http\Response;
use Hibla\Promise\Interfaces\PromiseInterface;

trait InterceptorTrait
{
    /**
     * Process response interceptors sequentially, handling both sync and async interceptors.
     */
    private function processResponseInterceptorsSequentially(
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
                        $this->processResponseInterceptorsSequentially(
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
                $this->processResponseInterceptorsSequentially(
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
}