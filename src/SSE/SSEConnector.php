<?php

declare(strict_types=1);

namespace Hibla\HttpClient\SSE;

use Hibla\HttpClient\Interfaces\Handler\HttpHandlerInterface;
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
     * @param HttpHandlerInterface $httpHandler The HTTP handler to use for the request
     * @param Request $request The initial request to use for the connection attempt
     * @param \Closure(RequestInterface): array<int|string, mixed> $optionsBuilder
     * @param \Closure $dispatcher
     */
    public function __construct(
        private readonly HttpHandlerInterface $httpHandler,
        private readonly Request $request,
        private readonly \Closure $optionsBuilder,
        private readonly \Closure $dispatcher
    ) {
    }

    /**
     * @param string $url The URL to connect to
     * @param callable|null $onEvent The callback to invoke on each event
     * @param callable|null $onError The callback to invoke on error
     * @param SSEReconnectConfig|null $reconnectConfig The reconnect config to use
     *
     * @return PromiseInterface<SSEResponseInterface> The promise for the SSE connection
     */
    public function __invoke(
        string $url,
        ?callable $onEvent,
        ?callable $onError,
        ?SSEReconnectConfig $reconnectConfig
    ): PromiseInterface {
        $executor = function (RequestInterface $processed) use ($onEvent, $onError, $reconnectConfig): PromiseInterface {
            $finalOptions = ($this->optionsBuilder)($processed);

            return $this->httpHandler->sse(
                (string) $processed->getUri(),
                $finalOptions,
                $onEvent,
                $onError,
                $reconnectConfig
            );
        };

        /** @var PromiseInterface<SSEResponse> $pipelinePromise */
        $pipelinePromise = ($this->dispatcher)($this->request, $executor, true);

        return new CancelableSSEPromise($pipelinePromise);
    }
}
