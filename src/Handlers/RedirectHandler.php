<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Handlers;

use Hibla\HttpClient\Exceptions\RequestException;
use Hibla\HttpClient\Interfaces\RequestInterface;
use Hibla\HttpClient\Interfaces\ResponseInterface;
use Hibla\HttpClient\Interfaces\SSEResponseInterface;
use Hibla\HttpClient\Interfaces\StreamingResponseInterface;
use Hibla\HttpClient\Utils\RedirectUriResolver;
use Hibla\Promise\Interfaces\PromiseInterface;

use function Hibla\async;
use function Hibla\await;

/**
 * Handles HTTP redirects recursively using a non-blocking fiber loop.
 *
 * @internal
 */
final readonly class RedirectHandler
{
    /**
     * @param array<callable> $interceptors
     */
    public function __construct(
        private InterceptorHandler $interceptorHandler,
        private array $interceptors,
        private bool $followRedirects,
        private int $maxRedirects
    ) {
    }

    /**
     * Dispatches the request and automatically follows redirects up to the configured limit.
     *
     * @template TResult
     *
     * @param RequestInterface $request The initial request to dispatch.
     * @param callable(RequestInterface): PromiseInterface<TResult> $executor The transport execution closure.
     * @param bool $requireResponse Whether the pipeline must strictly return a ResponseInterface.
     * @return PromiseInterface<TResult>
     */
    public function dispatch(
        RequestInterface $request,
        callable $executor,
        bool $requireResponse
    ): PromiseInterface {
        /** @var PromiseInterface<TResult> */
        return async(function () use ($request, $executor, $requireResponse) {
            $redirectCount = 0;
            $currentRequest = $request;

            while (true) {
                /** @var TResult $response */
                $response = await($this->interceptorHandler->process(
                    $currentRequest,
                    $this->interceptors,
                    $executor,
                    $requireResponse
                ));

                $statusCode = 0;
                /** @var string|null $location */
                $location = null;

                if ($response instanceof ResponseInterface) {
                    $statusCode = $response->getStatusCode();
                    $location = $response->getHeaderLine('Location');
                } elseif (\is_array($response) && isset($response['status'], $response['headers'])) {
                    /** @var int $statusCode */
                    $statusCode = $response['status'];
                    /** @var mixed $headers */
                    $headers = $response['headers'];

                    if (\is_iterable($headers)) {
                        foreach ($headers as $name => $values) {
                            if (\is_string($name) && \strtolower($name) === 'location') {
                                $locVal = \is_array($values) ? ($values[0] ?? '') : $values;
                                $location = \is_scalar($locVal) ? (string) $locVal : '';

                                break;
                            }
                        }
                    }
                } else {
                    return $response;
                }

                if (! $this->followRedirects || $statusCode < 300 || $statusCode >= 400 || $location === null || $location === '') {
                    return $response;
                }

                if ($redirectCount >= $this->maxRedirects) {
                    throw new RequestException(
                        "Will not follow more than {$this->maxRedirects} redirects",
                        0,
                        null,
                        (string) $currentRequest->getUri()
                    );
                }

                if ($response instanceof SSEResponseInterface || $response instanceof StreamingResponseInterface) {
                    $response->close();
                } elseif ($response instanceof ResponseInterface) {
                    $response->getBody()->close();
                }

                $newUri = RedirectUriResolver::resolve($currentRequest->getUri(), $location);

                $isCrossDomain = \strtolower($currentRequest->getUri()->getHost()) !== \strtolower($newUri->getHost())
                    || $currentRequest->getUri()->getPort() !== $newUri->getPort()
                    || $currentRequest->getUri()->getScheme() !== $newUri->getScheme();

                $currentRequest = clone $currentRequest;
                $currentRequest = $currentRequest->withUri($newUri);

                if ($statusCode === 303 || ($statusCode <= 302 && \in_array(\strtoupper($currentRequest->getMethod()), ['POST', 'PUT', 'DELETE'], true))) {
                    $currentRequest = $currentRequest->withMethod('GET')->body('');
                    $currentRequest = $currentRequest->withoutHeader('Content-Type')->withoutHeader('Content-Length');
                }

                if ($isCrossDomain) {
                    $currentRequest = $currentRequest->withoutHeader('Authorization')->withoutHeader('Cookie');
                }

                $redirectCount++;
            }
        });
    }
}