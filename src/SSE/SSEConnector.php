<?php

declare(strict_types=1);

namespace Hibla\HttpClient\SSE;

use Hibla\HttpClient\Handlers\HttpHandler;
use Hibla\HttpClient\Handlers\InterceptorHandler;
use Hibla\HttpClient\Interfaces\Execution\HttpInterceptorInterface;
use Hibla\HttpClient\Interfaces\RequestInterface;
use Hibla\HttpClient\Interfaces\SSEResponseInterface;
use Hibla\HttpClient\Request;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Bridges an SSE connection attempt through the request interceptor pipeline.
 *
 * @internal
 */
final class SSEConnector
{
    /**
     * @param InterceptorHandler $interceptorHandler The interceptor handler to use for the request pipeline
     * @param HttpHandler $httpHandler The HTTP handler to use for the request
     * @param array<HttpInterceptorInterface> $interceptors The interceptors to use for the request pipeline
     * @param Request $request The initial request to use for the connection attempt
     * @param \Closure(RequestInterface): array<int|string, mixed> $optionsBuilder
     */
    public function __construct(
        private readonly InterceptorHandler $interceptorHandler,
        private readonly HttpHandler $httpHandler,
        private readonly array $interceptors,
        private readonly Request $request,
        private readonly \Closure $optionsBuilder,
    ) {
    }

    /**
     * @param string $url The URL to connect to
     * @param callable|null $onEvent The callback to invoke on each event
     * @param callable|null $onError The callback to invoke on error
     * @param SSEReconnectConfig|null $reconnectConfig The reconnect config to use
     * @return PromiseInterface<SSEResponseInterface> The promise for the SSE connection
     */
    public function __invoke(
        string $url,
        ?callable $onEvent,
        ?callable $onError,
        ?SSEReconnectConfig $reconnectConfig
    ): PromiseInterface {
        $pipelinePromise = $this->interceptorHandler->process(
            $this->request,
            $this->interceptors,
            function (RequestInterface $processed) use ($onEvent, $onError, $reconnectConfig) {
                $finalOptions = ($this->optionsBuilder)($processed);

                return $this->httpHandler->sse(
                    (string) $processed->getUri(),
                    $finalOptions,
                    $onEvent,
                    $onError,
                    $reconnectConfig
                );
            }
        );

        return new CancelableSSEPromise($pipelinePromise);
    }
}