<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Execution;

use Hibla\HttpClient\Interfaces\HttpClientInterface;
use Hibla\HttpClient\Interfaces\ResponseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Contract for translating flat option arrays into client requests.
 */
interface FetchRequestInterface
{
    /**
     * Map options onto the client and execute the request.
     *
     * @param HttpClientInterface $client The client builder instance.
     * @param string $url The target URL.
     * @param array<int|string, mixed> $options Flat options array.
     * @return PromiseInterface<ResponseInterface>
     */
    public function send(
        HttpClientInterface $client,
        string $url,
        array $options = []
    ): PromiseInterface;
}
