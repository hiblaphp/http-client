<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function () {
    Http::startTesting();
});

afterEach(function () {
    Http::stopTesting();
});

describe('URI Template Mocking Integration', function () {
    test('it matches mocks against expanded simple parameters', function () {
        Http::mock('GET')
            ->url('https://api.example.com/users/123/profile')
            ->respondJson(['id' => 123, 'name' => 'John Doe'])
            ->register();

        $response = Http::request()
            ->withUrlParameter('id', 123)
            ->get('https://api.example.com/users/{id}/profile')
            ->wait();

        expect($response->json('name'))->toBe('John Doe');
        Http::assertRequestMade('GET', 'https://api.example.com/users/123/profile');
    });

    test('it correctly handles percent-encoding in templates during mock matching', function () {
        Http::mock('GET')
            ->url('https://api.example.com/search/hello%20world')
            ->respondWith('found')
            ->register();

        $response = Http::request()
            ->withUrlParameter('query', 'hello world')
            ->get('https://api.example.com/search/{query}')
            ->wait();

        expect($response->body())->toBe('found');
    });

    test('it handles reserved characters in templates ({+param}) during mock matching', function () {
        Http::mock('GET')
            ->url('https://api.example.com/files/deep/nested/file.txt')
            ->respondWith('content')
            ->register();

        $response = Http::request()
            ->withUrlParameter('path', 'deep/nested/file.txt')
            ->get('https://api.example.com/files/{+path}')
            ->wait();

        expect($response->body())->toBe('content');
    });

    test('unresolved placeholders remain in the URL and must match the mock literally', function () {
        Http::mock('GET')
            ->url('https://api.example.com/users/{missing}/data')
            ->respondWithStatus(404)
            ->register();

        $response = Http::get('https://api.example.com/users/{missing}/data')->wait();

        expect($response->status())->toBe(404);
    });
});