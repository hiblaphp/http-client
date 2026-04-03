<?php

declare(strict_types=1);

namespace Hibla\HttpClient\SSE;

use Hibla\HttpClient\Handlers\HttpHandler;
use Hibla\HttpClient\Handlers\InterceptorHandler;
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
    public function __construct(
        private readonly InterceptorHandler $interceptorHandler,
        private readonly HttpHandler $httpHandler,
        private readonly array $interceptors,
        private readonly Request $request,
    ) {
    }

    /**
     * @param string $url The URL to connect to
     * @param array $curlOptions The cURL options to pass to the request
     * @param callable|null $onEvent The callback to invoke on each event
     * @param callable|null $onError The callback to invoke on error
     * @param SSEReconnectConfig|null $reconnectConfig The reconnect config to use
     * @return PromiseInterface<SSEResponseInterface> The promise for the SSE connection
     */
    public function __invoke(
        string $url,
        array $curlOptions,
        ?callable $onEvent,
        ?callable $onError,
        ?SSEReconnectConfig $reconnectConfig
    ): PromiseInterface {
        $pipelinePromise = $this->interceptorHandler->process(
            $this->request,
            $this->interceptors,
            function (RequestInterface $processed) use ($curlOptions, $onEvent, $onError, $reconnectConfig) {
                $finalOptions = $curlOptions;
                $headerStrings = [];

                foreach ($processed->getHeaders() as $name => $values) {
                    $headerStrings[] = "{$name}: " . implode(', ', $values);
                }

                $finalOptions[CURLOPT_HTTPHEADER] = $headerStrings;

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