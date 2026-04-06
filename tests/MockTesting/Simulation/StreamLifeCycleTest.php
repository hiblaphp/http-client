<?php

declare(strict_types=1);

namespace Tests\MockTesting\Simulation;

use Hibla\HttpClient\Http;

beforeEach(function () {
    Http::startTesting();
});

afterEach(function () {
    Http::stopTesting();
});

describe('StreamingResponse Lifecycle Edge Cases', function () {
    test('it can interleave readAsync and body() calls', function () {
        Http::mock('GET')->url('/stream-1')->respondWithChunks(['part1', 'part2', 'part3'])->register();
        $response = Http::stream('/stream-1')->wait();

        $chunk1 = $response->readAsync(5)->wait();
        expect($chunk1)->toBe('part1');

        $fullBody = $response->body();
        expect($fullBody)->toBe('part1part2part3');
        expect($response->body())->toBe('part1part2part3');
    });

    test('readAsync returns null exactly at EOF', function () {
        Http::mock('GET')->url('/stream-2')->respondWith('short')->register();
        $response = Http::stream('/stream-2')->wait();

        expect($response->readAsync(100)->wait())->toBe('short');
        expect($response->readAsync(100)->wait())->toBeNull();
    });

    test('readLineAsync correctly handles newlines across mock chunks', function () {
        Http::mock('GET')
            ->url('/stream-3')
            ->respondWithChunks(["First Line\nSec", "ond Line\n", "Third Line"])
            ->register();

        $response = Http::stream('/stream-3')->wait();

        expect($response->readLineAsync()->wait())->toBe("First Line\n")
            ->and($response->readLineAsync()->wait())->toBe("Second Line\n")
            ->and($response->readLineAsync()->wait())->toBe("Third Line");
    });

    test('readAllAsync exhausts the entire mocked stream', function () {
        $longData = str_repeat('ABCDE', 1000);
        Http::mock('GET')->url('/stream-4')->respondWith($longData)->register();

        $response = Http::stream('/stream-4')->wait();
        $result = $response->readAllAsync()->wait();

        expect($result)->toBe($longData)
            ->and($response->eof())->toBeTrue();
    });

    test('rewind() allows re-reading a mocked stream after partial consumption', function () {
        Http::mock('GET')->url('/stream-5')->respondWith('0123456789')->register();
        $response = Http::stream('/stream-5')->wait();

        expect($response->readAsync(5)->wait())->toBe('01234');

        $response->rewind();
        expect($response->readAsync(10)->wait())->toBe('0123456789');
    });

     test('string conversion (casting) always returns full body via rewind', function () {
        Http::mock('GET')->url('/stream-6')->respondWith('Hello World')->register();
        $response = Http::stream('/stream-6')->wait();

        $response->readAsync(6)->wait(); 
        
        expect((string)$response)->toBe('Hello World');
    });
});