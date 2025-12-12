<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Handlers;

use Hibla\HttpClient\Exceptions\RequestException;
use Hibla\HttpClient\Response;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

/**
 * Handles sequential processing of response interceptors.
 */
final class ResponseInterceptorHandler
{
    /**
     * Process response interceptors sequentially.
     *
     * @param Response $response The initial response
     * @param array<callable(Response): (Response|PromiseInterface<Response>)> $interceptors Array of interceptor callbacks
     * @return PromiseInterface<Response> A promise that resolves with the processed response
     */
    public function processInterceptors(Response $response, array $interceptors): PromiseInterface
    {
        if ($interceptors === []) {
            return Promise::resolved($response);
        }

        /** @var Promise<Response> $promise */
        $promise = new Promise(function (callable $resolve, callable $reject) use ($response, $interceptors) {
            $this->processSequentially($response, $interceptors, $resolve, $reject);
        });

        return $promise;
    }

    /**
     * Process response interceptors sequentially, handling both sync and async interceptors.
     * @param array<callable(Response): (Response|PromiseInterface<Response>)> $interceptors
     */
    private function processSequentially(
        Response $response,
        array $interceptors,
        callable $resolve,
        callable $reject
    ): void {
        if ($interceptors === []) {
            $resolve($response);

            return;
        }

        $interceptor = array_shift($interceptors);

        try {
            $result = $interceptor($response);

            if ($result instanceof PromiseInterface) {
                // Async interceptor - wait for it to complete before processing next
                $result->then(
                    function (Response $asyncResponse) use ($interceptors, $resolve, $reject) {
                        $this->processSequentially(
                            $asyncResponse,
                            $interceptors,
                            $resolve,
                            $reject
                        );
                    },
                    $reject
                );
            } elseif ($result instanceof Response) {
                // Sync interceptor - process immediately and continue
                $this->processSequentially(
                    $result,
                    $interceptors,
                    $resolve,
                    $reject
                );
            } else {
                throw new RequestException('InterceptResponse() must return a Response or a PromiseInterface that resolves with a Response.');
            }
        } catch (\Throwable $e) {
            $reject($e);
        }
    }
}
