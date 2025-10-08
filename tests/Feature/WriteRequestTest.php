<?php

use Hibla\HttpClient\Http;

beforeEach(function () {
    Http::startTesting();
});

afterEach(function () {
    Http::stopTesting();
});

describe('DELETE Method', function () {

    it('handles a DELETE request correctly', function () {
        Http::mock()
            ->url('/users/1')
            ->respondWithStatus(204)
            ->register()
        ;

        $response = Http::request()->delete('/users/1')->await();

        Http::assertRequestMade('DELETE', '/users/1');
        expect($response->status())->toBe(204);
        expect($response->body())->toBeEmpty();
    });

});
