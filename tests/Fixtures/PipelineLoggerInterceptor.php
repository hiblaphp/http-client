<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Hibla\HttpClient\Interfaces\RequestInterface;
use Hibla\HttpClient\Interfaces\ResponseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * An invokable class for the full onion-style pipeline.
 */
class PipelineLoggerInterceptor
{
    /**
     * @var string[]
     */
    public array $history = [];

    /**
     * @param callable(RequestInterface): PromiseInterface<ResponseInterface> $next
     * @return PromiseInterface<ResponseInterface>
     */
    public function __invoke(RequestInterface $request, callable $next): PromiseInterface
    {
        $this->history[] = 'invokable-before';

        /** @var PromiseInterface<ResponseInterface> $promise */
        $promise = $next($request);

        return $promise->then(function (ResponseInterface $response) {
            $this->history[] = 'invokable-after';

            return $response;
        });
    }
}
