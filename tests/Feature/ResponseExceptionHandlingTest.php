<?php

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\Exceptions\NetworkException;

beforeEach(function () {
    Http::startTesting();
});

afterEach(function () {
    Http::stopTesting();
});

describe('Response and Exception Handling', function () {

    it('returns a Response object on 4xx status codes', function () {
        Http::mock()->url('/client-error')->status(404)->respondJson(['error' => 'Not Found'])->register();

        $response = Http::get('/client-error')->await();

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeFalse();
        expect($response->clientError())->toBeTrue();
        expect($response->serverError())->toBeFalse();
        expect($response->status())->toBe(404);
        expect($response->json())->toBe(['error' => 'Not Found']);
    });

    it('returns a Response object on 5xx status codes', function () {
        Http::mock()->url('/server-error')->status(503)->respondWith('Service Unavailable')->register();

        $response = Http::get('/server-error')->await();

        expect($response)->toBeInstanceOf(Response::class);
        expect($response->ok())->toBeFalse();
        expect($response->clientError())->toBeFalse();
        expect($response->serverError())->toBeTrue();
        expect($response->status())->toBe(503);
        expect($response->body())->toBe('Service Unavailable');
    });

    it('throws a NetworkException on a connection failure', function () {
        Http::mock()->url('/network-error')->fail('Connection refused')->register();

        $promise = Http::get('/network-error');

        expect(fn() => $promise->await())
            ->toThrow(NetworkException::class, 'Connection refused');
    });

});