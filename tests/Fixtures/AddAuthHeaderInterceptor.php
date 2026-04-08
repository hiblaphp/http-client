<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Hibla\HttpClient\Interfaces\RequestInterface;

/**
 * An invokable class for simple request transformation.
 */
class AddAuthHeaderInterceptor
{
    public function __invoke(RequestInterface $request): RequestInterface
    {
        return $request->withHeader('X-Invokable-Auth', 'active');
    }
}
