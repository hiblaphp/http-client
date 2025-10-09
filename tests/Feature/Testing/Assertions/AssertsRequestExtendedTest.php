<?php

use Hibla\HttpClient\Testing\Utilities\RecordedRequest;
use PHPUnit\Framework\AssertionFailedError;

describe('AssertsRequestsExtended', function () {
    test('assertRequestMatchingUrl validates URL pattern', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/api/users/123')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com/api/users/123')->await();

        expect(fn() => $handler->assertRequestMatchingUrl('GET', 'https://example.com/api/users/*'))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertRequestSequence validates request order', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/1')->respondWithStatus(200)->register();
        $handler->mock('GET')->url('https://example.com/2')->respondWithStatus(200)->register();
        $handler->mock('GET')->url('https://example.com/3')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com/1')->await();
        $handler->fetch('https://example.com/2')->await();
        $handler->fetch('https://example.com/3')->await();

        expect(fn() => $handler->assertRequestSequence([
            ['method' => 'GET', 'url' => 'https://example.com/1'],
            ['method' => 'GET', 'url' => 'https://example.com/2'],
            ['method' => 'GET', 'url' => 'https://example.com/3'],
        ]))->not->toThrow(AssertionFailedError::class);
    });

    test('assertRequestAtIndex validates request at specific index', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/1')->respondWithStatus(200)->register();
        $handler->mock('GET')->url('https://example.com/2')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com/1')->await();
        $handler->fetch('https://example.com/2')->await();

        expect(fn() => $handler->assertRequestAtIndex('GET', 'https://example.com/2', 1))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertSingleRequestTo validates single request to URL', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();
        $handler->fetch('https://example.com')->await();

        expect(fn() => $handler->assertSingleRequestTo('https://example.com'))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertSingleRequestTo fails when multiple requests made', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();
        $handler->fetch('https://example.com')->await();
        $handler->fetch('https://example.com')->await();

        expect(fn() => $handler->assertSingleRequestTo('https://example.com'))
            ->toThrow(AssertionFailedError::class);
    });

    test('assertRequestNotMade validates request was not made', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/1')->respondWithStatus(200)->register();
        $handler->fetch('https://example.com/1')->await();

        expect(fn() => $handler->assertRequestNotMade('GET', 'https://example.com/2'))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertRequestCountTo validates max request count', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();
        $handler->fetch('https://example.com')->await();
        $handler->fetch('https://example.com')->await();

        expect(fn() => $handler->assertRequestCountTo('https://example.com', 2))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('getRequestsTo returns requests to URL', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();
        $handler->fetch('https://example.com')->await();
        $handler->fetch('https://example.com')->await();

        $requests = $handler->getRequestsTo('https://example.com');

        expect($requests)->toHaveCount(2);
    });

    test('getRequestsByMethod returns requests by method', function () {
        $handler = testingHttpHandler();
        $handler->mock('GET')->url('https://example.com/1')->respondWithStatus(200)->register();
        $handler->mock('POST')->url('https://example.com/2')->respondWithStatus(200)->register();

        $handler->fetch('https://example.com/1')->await();
        $handler->fetch('https://example.com/2', ['method' => 'POST'])->await();

        $getRequests = $handler->getRequestsByMethod('GET');
        $postRequests = $handler->getRequestsByMethod('POST');

        expect($getRequests)->toHaveCount(1)
            ->and($postRequests)->toHaveCount(1)
            ->and($getRequests[0]->getMethod())->toBe('GET')
            ->and($postRequests[0]->getMethod())->toBe('POST');
    });
});