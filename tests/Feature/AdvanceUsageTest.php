<?php

use Hibla\HttpClient\Http;
use Hibla\Promise\Promise;

beforeEach(function () {
    Http::startTesting();
});

afterEach(function () {
    Http::stopTesting();
});

describe('Advanced Asynchronous Features', function () {

    it('handles multiple concurrent requests correctly', function () {
        Http::mock()->url('/users/1')->respondJson(['id' => 1])->register();
        Http::mock()->url('/users/2')->respondJson(['id' => 2])->register();
        Http::mock()->url('/posts/1')->respondJson(['postId' => 1])->register();

        $promises = [
            Http::get('/users/1'),
            Http::get('/users/2'),
            Http::get('/posts/1'),
        ];

        $results = Promise::all($promises)->await();

        Http::assertRequestCount(3);
        expect($results[0]->json())->toBe(['id' => 1]);
        expect($results[1]->json())->toBe(['id' => 2]);
        expect($results[2]->json())->toBe(['postId' => 1]);
    });

    it('can be timed out before it completes', function () {
        Http::mock()->url('/cancellable')->delay(2)->respondWith('Too late')->register();

        $promise = Http::get('/cancellable');

        $timedPromise = Promise::timeout($promise, 0.1);

        expect(fn () => $timedPromise->await())->toThrow(Exception::class);

        Http::assertRequestCount(1);
    });

    it('correctly uses the fetch API with options', function () {
        Http::mock()->url('/fetch-test')->respondWith('OK')->register();

        Http::fetch('/fetch-test', [
            'method' => 'POST',
            'headers' => ['X-Is-Fetch' => 'true'],
            'body' => 'raw body',
        ])->await();

        Http::assertRequestMade('POST', '/fetch-test');
        Http::assertHeaderSent('X-Is-Fetch', 'true');
        Http::assertRequestWithBody('POST', '/fetch-test', 'raw body');
        expect(true)->toBeTrue();
    });

});
