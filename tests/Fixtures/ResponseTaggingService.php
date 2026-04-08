<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Hibla\HttpClient\Interfaces\ResponseInterface;

/**
 * A standard class with a named method to test [$obj, 'method'] callables.
 */
class ResponseTaggingService
{
    public function tagResponse(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('X-Service-Tagged', 'true');
    }
}
