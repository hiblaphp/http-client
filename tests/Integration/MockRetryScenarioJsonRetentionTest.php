<?php

use Hibla\HttpClient\Http;

beforeEach(function () {
    Http::startTesting();
});

afterEach(function () {
    Http::stopTesting();
});

describe('Retry Scenarios JSON Data Retention', function () {
    test('slowlyImproveUntilAttempt retains custom JSON after retries', function () {
        Http::mock('GET')
            ->url('https://api.example.com/improving')
            ->slowlyImproveUntilAttempt(3, 1.0)
            ->respondJson(['success' => true, 'data' => 'improved', 'id' => 123])
            ->persistent()
            ->register();

        $start = microtime(true);
        $response = Http::retry(5, 0.01)
            ->get('https://api.example.com/improving')
            ->await();
        $duration = microtime(true) - $start;

        expect($response->status())->toBe(200)
            ->and($duration)->toBeGreaterThan(1.0)
            ->and($response->json())->toMatchArray([
                'success' => true,
                'data' => 'improved',
                'id' => 123,
            ])
            ->and($response->json()['success'])->toBeTrue()
            ->and($response->json()['id'])->toBe(123);
    });

    test('failUntilAttempt retains JSON with attempt tracking', function () {
        Http::mock('GET')
            ->url('https://api.example.com/fail-retry')
            ->failUntilAttempt(3, 'Temporary failure')
            ->persistent()
            ->register();

        $start = microtime(true);
        $response = Http::retry(5, 0.01)
            ->get('https://api.example.com/fail-retry')
            ->await();
        $duration = microtime(true) - $start;

        expect($response->status())->toBe(200)
            ->and($duration)->toBeGreaterThan(0.2)
            ->and($response->json())->toMatchArray([
                'success' => true,
                'attempt' => 3,
            ])
            ->and($response->json()['success'])->toBeTrue()
            ->and($response->json()['attempt'])->toBe(3);
    });

    test('failUntilAttempt retains custom JSON structure', function () {
        Http::mock('GET')
            ->url('https://api.example.com/custom-retry')
            ->failUntilAttempt(2, 'Network error')
            ->respondJson(['user' => 'John Doe', 'email' => 'john@example.com', 'active' => true])
            ->persistent()
            ->register();

        $response = Http::retry(5, 0.01)
            ->get('https://api.example.com/custom-retry')
            ->await();

        expect($response->status())->toBe(200)
            ->and($response->json())->toMatchArray([
                'user' => 'John Doe',
                'email' => 'john@example.com',
                'active' => true,
            ])
            ->and($response->json()['user'])->toBe('John Doe')
            ->and($response->json()['email'])->toBe('john@example.com')
            ->and($response->json()['active'])->toBeTrue();
    });

    test('timeoutUntilAttempt retains nested JSON data', function () {
        Http::mock('GET')
            ->url('https://api.example.com/timeout-retry')
            ->timeoutUntilAttempt(2, 0.1)
            ->respondJson([
                'success' => true,
                'message' => 'No more timeouts',
                'data' => ['key' => 'value'],
            ])
            ->persistent()
            ->register();

        $start = microtime(true);
        $response = Http::retry(5, 0.01)
            ->get('https://api.example.com/timeout-retry')
            ->await();
        $duration = microtime(true) - $start;

        expect($response->status())->toBe(200)
            ->and($duration)->toBeGreaterThan(0.1)
            ->and($response->json())->toHaveKey('data')
            ->and($response->json()['data'])->toHaveKey('key')
            ->and($response->json()['data']['key'])->toBe('value')
            ->and($response->json()['success'])->toBeTrue()
            ->and($response->json()['message'])->toBe('No more timeouts');
    });

    test('failWithSequence retains complex JSON with arrays', function () {
        Http::mock('GET')
            ->url('https://api.example.com/sequence')
            ->failWithSequence([
                'First error',
                ['error' => 'Second error', 'delay' => 0.05],
                ['error' => 'Third error', 'retryable' => true],
            ], ['final' => 'success', 'items' => [1, 2, 3], 'count' => 3])
            ->persistent()
            ->register();

        $start = microtime(true);
        $response = Http::retry(5, 0.01)
            ->get('https://api.example.com/sequence')
            ->await();
        $duration = microtime(true) - $start;

        expect($response->status())->toBe(200)
            ->and($duration)->toBeGreaterThan(0.2)
            ->and($response->json())->toMatchArray([
                'final' => 'success',
                'items' => [1, 2, 3],
                'count' => 3,
            ])
            ->and($response->json()['final'])->toBe('success')
            ->and($response->json()['items'])->toBe([1, 2, 3])
            ->and($response->json()['count'])->toBe(3);
    });

    test('rateLimitedUntilAttempt retains JSON after rate limit cleared', function () {
        Http::mock('GET')
            ->url('https://api.example.com/rate-limited')
            ->rateLimitedUntilAttempt(2)
            ->respondJson(['status' => 'ok', 'rate_limit' => 'cleared'])
            ->persistent()
            ->register();

        $response = Http::retry(5, 0.01)
            ->get('https://api.example.com/rate-limited')
            ->await();

        expect($response->status())->toBe(200)
            ->and($response->json())->toMatchArray([
                'status' => 'ok',
                'rate_limit' => 'cleared',
            ])
            ->and($response->json()['status'])->toBe('ok')
            ->and($response->json()['rate_limit'])->toBe('cleared');
    });

    test('failUntilAttempt retains deeply nested JSON structures', function () {
        Http::mock('POST')
            ->url('https://api.example.com/complex')
            ->failUntilAttempt(2)
            ->respondJson([
                'user' => [
                    'id' => 456,
                    'name' => 'Jane Smith',
                    'profile' => [
                        'age' => 30,
                        'city' => 'New York',
                    ],
                ],
                'metadata' => [
                    'timestamp' => '2025-10-10T12:00:00Z',
                    'version' => '1.0',
                ],
            ])
            ->persistent()
            ->register();

        $response = Http::retry(5, 0.01)
            ->post('https://api.example.com/complex')
            ->await();

        expect($response->status())->toBe(200)
            ->and($response->json())->toHaveKey('user')
            ->and($response->json())->toHaveKey('metadata')
            ->and($response->json()['user']['profile']['city'])->toBe('New York')
            ->and($response->json()['user']['profile']['age'])->toBe(30)
            ->and($response->json()['user']['name'])->toBe('Jane Smith')
            ->and($response->json()['metadata']['version'])->toBe('1.0')
            ->and($response->json()['metadata']['timestamp'])->toBe('2025-10-10T12:00:00Z');
    });

    test('multiple retry scenarios work independently', function () {
        Http::mock('GET')
            ->url('https://api.example.com/endpoint1')
            ->failUntilAttempt(2)
            ->respondJson(['endpoint' => 1])
            ->persistent()
            ->register();

        Http::mock('GET')
            ->url('https://api.example.com/endpoint2')
            ->timeoutUntilAttempt(2, 0.1)
            ->respondJson(['endpoint' => 2])
            ->persistent()
            ->register();

        Http::mock('GET')
            ->url('https://api.example.com/endpoint3')
            ->rateLimitedUntilAttempt(2)
            ->respondJson(['endpoint' => 3])
            ->persistent()
            ->register();

        $response1 = Http::retry(5, 0.01)->get('https://api.example.com/endpoint1')->await();
        $response2 = Http::retry(5, 0.01)->get('https://api.example.com/endpoint2')->await();
        $response3 = Http::retry(5, 0.01)->get('https://api.example.com/endpoint3')->await();

        expect($response1->json()['endpoint'])->toBe(1)
            ->and($response2->json()['endpoint'])->toBe(2)
            ->and($response3->json()['endpoint'])->toBe(3);
    });

    test('retry scenarios preserve special characters in JSON', function () {
        Http::mock('GET')
            ->url('https://api.example.com/special-chars')
            ->failUntilAttempt(2)
            ->respondJson([
                'message' => 'Hello "World" with \'quotes\'',
                'unicode' => '🎉 Success!',
                'special' => '<>&"\'\n\t',
            ])
            ->persistent()
            ->register();

        $response = Http::retry(5, 0.01)
            ->get('https://api.example.com/special-chars')
            ->await();

        expect($response->json()['message'])->toBe('Hello "World" with \'quotes\'')
            ->and($response->json()['unicode'])->toBe('🎉 Success!')
            ->and($response->json()['special'])->toBe('<>&"\'\n\t');
    });

    test('retry scenarios preserve null and boolean values', function () {
        Http::mock('GET')
            ->url('https://api.example.com/types')
            ->failUntilAttempt(2)
            ->respondJson([
                'null_value' => null,
                'true_value' => true,
                'false_value' => false,
                'zero' => 0,
                'empty_string' => '',
                'empty_array' => [],
            ])
            ->persistent()
            ->register();

        $response = Http::retry(5, 0.01)
            ->get('https://api.example.com/types')
            ->await();

        expect($response->json()['null_value'])->toBeNull()
            ->and($response->json()['true_value'])->toBeTrue()
            ->and($response->json()['false_value'])->toBeFalse()
            ->and($response->json()['zero'])->toBe(0)
            ->and($response->json()['empty_string'])->toBe('')
            ->and($response->json()['empty_array'])->toBe([]);
    });
});