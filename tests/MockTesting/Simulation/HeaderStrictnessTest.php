<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Testing\Exceptions\UnexpectedRequestException;

beforeEach(function () {
    Http::startTesting();
});

afterEach(function () {
    Http::stopTesting();
});

describe('Mock Header Matching Strategy and Validation', function () {
    it('matches when the request contains a superset of the expected headers', function () {
        Http::mock('GET')
            ->url('/headers-1')
            ->expectHeader('X-Required', 'yes')
            ->respondWith('matched')
            ->register();

        $response = Http::request()
            ->withHeader('X-Required', 'yes')
            ->withHeader('X-Unnecessary', 'ignored')
            ->get('/headers-1')
            ->wait();

        expect($response->body())->toBe('matched');
    });

    it('fails to match if a required header is missing entirely', function () {
        Http::mock('GET')->url('/headers-2')->expectHeader('X-Mandatory', 'exists')->register();

        expect(fn() => Http::get('/headers-2')->wait())
            ->toThrow(UnexpectedRequestException::class);
    });

    it('fails to match if the header key exists but the value is different', function () {
        Http::mock('GET')->url('/headers-3')->expectHeader('X-Version', 'v1')->register();

        expect(fn() => Http::request()->withHeader('X-Version', 'v2')->get('/headers-3')->wait())
            ->toThrow(UnexpectedRequestException::class);
    });

    it('handles case-insensitivity in both keys and values during matching', function () {
        Http::mock('GET')
            ->url('/headers-4')
            ->expectHeader('x-api-key', 'SECRET')
            ->respondWith('authenticated')
            ->register();

        $response = Http::request()
            ->withHeader('X-API-Key', 'SECRET')
            ->get('/headers-4')
            ->wait();

        expect($response->body())->toBe('authenticated');
    });

    it('correctly matches multiple headers using the expectHeaders() bulk method', function () {
        Http::mock('POST')
            ->url('/headers-5')
            ->expectHeaders([
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest'
            ])
            ->respondWith('bulk_matched')
            ->register();

        $response = Http::request()
            ->accept('application/json')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->post('/headers-5')
            ->wait();

        expect($response->body())->toBe('bulk_matched');
    });

    it('matches correctly when a header has multiple values', function () {
        Http::mock('GET')
            ->url('/headers-6')
            ->expectHeader('X-Multi', 'val1, val2')
            ->respondWith('multi_matched')
            ->register();

        $response = Http::request()
            ->withHeader('X-Multi', ['val1', 'val2'])
            ->get('/headers-6')
            ->wait();

        expect($response->body())->toBe('multi_matched');
    });
});