<?php

use Hibla\HttpClient\Http;

beforeEach(function () {
    Http::startTesting();
    Http::getTestingHandler()->reset();
});

afterEach(function () {
    Http::stopTesting();
});

describe('HTTP Client Caching', function () {

    it('caches a successful GET request', function () {
        Http::mock()->url('/cache-me')->respondJson(['data' => 'live'])->register();

        $response1 = Http::cache(60)->get('/cache-me')->await();
        $response2 = Http::cache(60)->get('/cache-me')->await();

        expect($response1->json())->toBe(['data' => 'live']);
        expect($response2->json())->toBe(['data' => 'live']);
        Http::assertRequestCount(1);
    });

    it('is faster than a non-cached request with a delay', function () {
        Http::mock()
            ->url('/slow-endpoint')
            ->delay(1)
            ->respondJson(['data' => 'slow response'])
            ->register()
        ;

        $start1 = microtime(true);
        $response1 = Http::cache(60)->get('/slow-endpoint')->await();
        $duration1 = microtime(true) - $start1;

        $start2 = microtime(true);
        $response2 = Http::cache(60)->get('/slow-endpoint')->await();
        $duration2 = microtime(true) - $start2;

        Http::assertRequestCount(1);
        expect($response1->json())->toBe(['data' => 'slow response']);
        expect($response2->json())->toBe(['data' => 'slow response']);
        expect($duration1)->toBeGreaterThan(1.0);
        expect($duration2)->toBeLessThan(0.1);
    });

    it('does not cache non-GET requests', function () {
        Http::mock()->url('/no-cache')->persistent()->respondWith('OK')->register();

        Http::cache(60)->post('/no-cache', ['data' => '1'])->await();
        Http::cache(60)->post('/no-cache', ['data' => '1'])->await();

        Http::assertRequestCount(2);
    });

    it('creates separate cache entries for different URLs', function () {
        Http::mock()->url('/data/1')->respondJson(['id' => 1])->register();
        Http::mock()->url('/data/2')->respondJson(['id' => 2])->register();

        Http::cache(60)->get('/data/1')->await();
        Http::cache(60)->get('/data/2')->await();

        Http::assertRequestCount(2);
    });

    it('respects a custom cache key', function () {
        Http::mock()
            ->url('*')
            ->persistent()
            ->respondJson(['data' => 'shared'])
            ->register()
        ;

        $response1 = Http::cacheWithKey('my-shared-key')->get('/any-url')->await();
        $response2 = Http::cacheWithKey('my-shared-key')->get('/another-url')->await();

        expect($response1->json())->toBe(['data' => 'shared']);
        expect($response2->json())->toBe(['data' => 'shared']);
        Http::assertRequestCount(1);
    });

});
