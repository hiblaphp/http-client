<?php

use Hibla\HttpClient\Http;

beforeEach(function () {
    Http::startTesting();
});

afterEach(function () {
    Http::stopTesting();
});

describe('Read-Oriented HTTP Methods', function () {

    it('handles a GET request correctly', function () {
        Http::mock()
            ->url('/users')
            ->respondJson(['data' => 'user list'])
            ->register()
        ;

        $response = Http::request()->get('/users')->await();

        Http::assertRequestMade('GET', '/users');
        expect($response->ok())->toBeTrue();
        expect($response->json())->toBe(['data' => 'user list']);
    });

    it('handles a GET request with query parameters', function () {
        Http::mock()
            ->url('/users?status=active')
            ->respondJson(['data' => 'active users'])
            ->register()
        ;

        $response = Http::request()->get('/users', ['status' => 'active'])->await();

        Http::assertRequestMade('GET', '/users?status=active');
        expect($response->json())->toBe(['data' => 'active users']);
    });

    it('handles a HEAD request correctly', function () {
        Http::mock()
            ->url('/resource')
            ->respondWith('') // HEAD responses have no body
            ->respondWithHeader('Content-Length', '12345')
            ->register()
        ;

        $response = Http::request()->head('/resource')->await();

        Http::assertRequestMade('HEAD', '/resource');
        expect($response->ok())->toBeTrue();
        expect($response->body())->toBeEmpty();
        expect($response->header('Content-Length'))->toBe('12345');
    });

    it('handles an OPTIONS request correctly', function () {
        Http::mock()
            ->url('/resource')
            ->respondWithHeader('Allow', 'GET, POST, OPTIONS')
            ->register()
        ;

        $response = Http::request()->options('/resource')->await();

        Http::assertRequestMade('OPTIONS', '/resource');
        expect($response->header('Allow'))->toBe('GET, POST, OPTIONS');
    });

    it('handles a custom method via send()', function () {
        Http::mock('TRACE')
            ->url('/debug')
            ->respondWith('Trace complete')
            ->register()
        ;

        $response = Http::request()->send('TRACE', '/debug')->await();

        Http::assertRequestMade('TRACE', '/debug');
        expect($response->body())->toBe('Trace complete');
    });

});
