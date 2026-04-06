<?php

declare(strict_types=1);

namespace Tests\MockTesting\Simulation;

use Hibla\HttpClient\Http;
use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\StreamingResponse;

beforeEach(function () {
    Http::startTesting();
});

afterEach(function () {
    Http::stopTesting();
});

describe('Resource Leakage and Cancellation', function () {
    test('cancelling a promise during backoff kills the retry loop', function () {
        $requestCount = 0;
        
        Http::mock('GET')
            ->url('/retry-leak-test')
            ->expect(function() use (&$requestCount) {
                $requestCount++;
                return true;
            })
            ->status(503)
            ->persistent()
            ->register();

        $promise = Http::retry(maxRetries: 5, baseDelay: 0.5) 
            ->get('/retry-leak-test');

        Loop::addTimer(0.1, function() use ($promise) {
            $promise->cancel();
        });

        Loop::run();

        expect($requestCount)->toBe(1);
    });

    test('closing a streaming response stops the mock chunk delivery', function () {
        $chunksDelivered = 0;
        
        Http::mock('GET')
            ->url('/stream-leak-test')
            ->respondWithChunks(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'])
            ->dataStreamTransferLatency(0.1)
            ->register();

        $promise = Http::stream('/stream-leak-test', function($chunk) use (&$chunksDelivered) {
            $chunksDelivered++;
        });

        $promise->then(function(StreamingResponse $response) {
            Loop::addTimer(0.25, function() use ($response) {
                $response->close();
            });
        });

        Loop::run();

        expect($chunksDelivered)->toBeLessThan(5);
    });
});