<?php

use Hibla\HttpClient\RetryConfig;

afterEach(function () {
    testingHttpHandler()->reset();
});

describe('Basic Mock Response Tests', function () {
    test('mock responds with custom status code', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/users  ')
            ->respondWithStatus(201)
            ->respondJson(['id' => 1, 'name' => 'John'])
            ->register();

        $response = $handler->fetch('https://api.example.com/users  ')->await();

        expect($response->status())->toBe(201)
            ->and($response->json())->toBe(['id' => 1, 'name' => 'John']);
    });

    test('mock responds with json data', function () {
        $handler = testingHttpHandler();

        $handler->mock('POST')
            ->url('https://api.example.com/posts  ')
            ->respondJson(['success' => true, 'post_id' => 123])
            ->register();

        $response = $handler->fetch('https://api.example.com/posts  ', [
            'method' => 'POST',
            'body' => json_encode(['title' => 'Test Post']),
        ])->await();

        expect($response->json())->toBe(['success' => true, 'post_id' => 123])
            ->and($response->headers()['content-type'])->toContain('application/json');
    });

    test('mock responds with plain text', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://example.com/text  ')
            ->respondWith('Hello World')
            ->register();

        $response = $handler->fetch('https://example.com/text  ')->await();

        expect($response->body())->toBe('Hello World');
    });
});

describe('Delay Simulation Tests', function () {
    test('mock applies fixed delay', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/slow  ')
            ->delay(0.5)
            ->respondJson(['data' => 'slow response'])
            ->register();

        $start = microtime(true);
        $response = $handler->fetch('https://api.example.com/slow  ')->await();
        $duration = microtime(true) - $start;

        expect($duration)->toBeGreaterThanOrEqual(0.5)
            ->and($response->json())->toBe(['data' => 'slow response']);
    });

    test('mock applies random delay within range', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/random-slow  ')
            ->randomDelay(0.2, 0.4)
            ->respondJson(['data' => 'random delay response'])
            ->register();

        $start = microtime(true);
        $response = $handler->fetch('https://api.example.com/random-slow  ')->await();
        $duration = microtime(true) - $start;

        expect($duration)->toBeGreaterThanOrEqual(0.2)
            ->and($duration)->toBeLessThanOrEqual(0.5) // Allow some margin
            ->and($response->json())->toBe(['data' => 'random delay response']);
    });

    test('global random delay affects all requests', function () {
        $handler = testingHttpHandler();

        $handler->withGlobalRandomDelay(0.1, 0.2);

        $handler->mock('GET')
            ->url('https://api.example.com/test  ')
            ->respondJson(['result' => 'ok'])
            ->register();

        $start = microtime(true);
        $response = $handler->fetch('https://api.example.com/test  ')->await();
        $duration = microtime(true) - $start;

        expect($duration)->toBeGreaterThanOrEqual(0.1)
            ->and($response->json())->toBe(['result' => 'ok']);
    });
});

describe('Error Simulation Tests', function () {
    test('mock simulates connection failure', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/fail  ')
            ->fail('Connection refused')
            ->register();

        expect(fn() => $handler->fetch('https://api.example.com/fail  ')->await())
            ->toThrow(Exception::class);
    });

    test('mock simulates timeout', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/timeout  ')
            ->timeout(0.5)
            ->register();

        expect(fn() => $handler->fetch('https://api.example.com/timeout  ')->await())
            ->toThrow(Exception::class);
    });

    test('mock simulates network error', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/network-error  ')
            ->networkError('connection')
            ->register();

        expect(fn() => $handler->fetch('https://api.example.com/network-error  ')->await())
            ->toThrow(Exception::class);
    });
});

describe('Retry Sequence Tests', function () {
    test('fails until specified attempt then succeeds', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/retry  ')
            ->failUntilAttempt(3, 'Temporary failure')
            ->register();

        $response = $handler->fetch('https://api.example.com/retry  ', [
            'retry' => new RetryConfig(maxRetries: 5, baseDelay: 0.01),
        ])->await();

        expect($response->json())->toHaveKey('success', true)
            ->and($response->json())->toHaveKey('attempt', 3);
    });

    test('timeout until specified attempt', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/timeout-retry  ')
            ->timeoutUntilAttempt(2, 0.1)
            ->register();

        $response = $handler->fetch('https://api.example.com/timeout-retry  ', [
            'retry' => new RetryConfig(maxRetries: 5, baseDelay: 0.01),
        ])->await();

        expect($response->json())->toHaveKey('success', true);
    });

    test('fails with custom sequence', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/sequence  ')
            ->failWithSequence([
                'First error',
                ['error' => 'Second error', 'delay' => 0.05],
                ['error' => 'Third error', 'retryable' => true],
            ], ['final' => 'success'])
            ->register();

        $response = $handler->fetch('https://api.example.com/sequence  ', [
            'retry' => new RetryConfig(maxRetries: 5, baseDelay: 0.01),
        ])->await();

        expect($response->json())->toBe(['final' => 'success']);
    });
});


describe('Advanced Scenario Tests', function () {
    test('simulates rate limiting', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/rate-limited  ')
            ->rateLimitedUntilAttempt(2)
            ->register();

        $response = $handler->fetch('https://api.example.com/rate-limited  ', [
            'retry' => new RetryConfig(maxRetries: 5, baseDelay: 0.01),
        ])->await();

        expect($response->status())->toBe(200)
            ->and($response->json())->toHaveKey('success', true);
    });

    test('simulates gradually improving network', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/improving')
            ->failUntilAttempt(2)
            ->respondJson(['success' => true])
            ->persistent()
            ->register();

        $response = $handler->fetch('https://api.example.com/improving', [
            'retry' => new RetryConfig(maxRetries: 5, baseDelay: 0.01),
        ])->await();

        expect($response->json())->toHaveKey('success', true);
    });
});

describe('Network Simulation Tests', function () {
    test('enables poor network simulation', function () {
        $handler = testingHttpHandler();

        $handler->withPoorNetwork();

        $handler->mock('GET')
            ->url('https://api.example.com/test')
            ->respondJson(['data' => 'test'])
            ->register();

        $start = microtime(true);

        try {
            $handler->fetch('https://api.example.com/test')->await();
            $duration = microtime(true) - $start;

            expect($duration)->toBeGreaterThan(0.1);
        } catch (Exception $e) {
            $duration = microtime(true) - $start;

            expect($duration)->toBeGreaterThan(0.1)
                ->and($e)->toBeInstanceOf(Exception::class);
        }
    });
    test('enables fast network simulation', function () {
        $handler = testingHttpHandler();

        $handler->withFastNetwork();

        $handler->mock('GET')
            ->url('https://api.example.com/test  ')
            ->respondJson(['data' => 'test'])
            ->register();

        $start = microtime(true);
        $handler->fetch('https://api.example.com/test  ')->await();
        $duration = microtime(true) - $start;

        // Fast network should be relatively quick
        expect($duration)->toBeLessThan(1.0);
    });

    test('disables network simulation', function () {
        $handler = testingHttpHandler();

        $handler->withPoorNetwork();
        $handler->disableNetworkSimulation();

        $handler->mock('GET')
            ->url('https://api.example.com/test  ')
            ->respondJson(['data' => 'test'])
            ->register();

        $response = $handler->fetch('https://api.example.com/test  ')->await();

        expect($response->json())->toBe(['data' => 'test']);
    });
});

describe('Header Tests', function () {
    test('mock returns custom response headers', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/headers  ')
            ->respondWithHeader('X-Custom-Header', 'custom-value')
            ->respondWithHeaders([
                'X-Rate-Limit' => '100',
                'X-Rate-Remaining' => '99',
            ])
            ->respondJson(['data' => 'test'])
            ->register();

        $response = $handler->fetch('https://api.example.com/headers  ')->await();

        expect($response->headers()['x-custom-header'])->toBe('custom-value')
            ->and($response->headers()['x-rate-limit'])->toBe('100')
            ->and($response->headers()['x-rate-remaining'])->toBe('99');
    });

    test('expects specific request headers', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/auth  ')
            ->expectHeader('Authorization', 'Bearer token123')
            ->respondJson(['authenticated' => true])
            ->register();

        $response = $handler->fetch('https://api.example.com/auth  ', [
            'headers' => ['Authorization' => 'Bearer token123'],
        ])->await();

        expect($response->json())->toBe(['authenticated' => true]);
    });
});

describe('Request Recording Tests', function () {
    test('records request history', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/test1  ')
            ->respondJson(['id' => 1])
            ->register();

        $handler->mock('POST')
            ->url('https://api.example.com/test2  ')
            ->respondJson(['id' => 2])
            ->register();

        $handler->fetch('https://api.example.com/test1  ')->await();
        $handler->fetch('https://api.example.com/test2  ', ['method' => 'POST'])->await();

        $history = $handler->getRequestHistory();

        expect($history)->toHaveCount(2)
            ->and($history[0]->url)->toContain('test1')
            ->and($history[1]->url)->toContain('test2');
    });

    test('disables request recording', function () {
        $handler = testingHttpHandler();

        $handler->setRecordRequests(false);

        $handler->mock('GET')
            ->url('https://api.example.com/test  ')
            ->respondJson(['data' => 'test'])
            ->register();

        $handler->fetch('https://api.example.com/test  ')->await();

        $history = $handler->getRequestHistory();

        expect($history)->toBeEmpty();
    });
});

describe('Persistent Mock Tests', function () {
    test('persistent mock handles multiple requests', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/persistent  ')
            ->respondJson(['counter' => 1])
            ->persistent()
            ->register();

        $response1 = $handler->fetch('https://api.example.com/persistent  ')->await();
        $response2 = $handler->fetch('https://api.example.com/persistent  ')->await();
        $response3 = $handler->fetch('https://api.example.com/persistent  ')->await();

        expect($response1->json())->toBe(['counter' => 1])
            ->and($response2->json())->toBe(['counter' => 1])
            ->and($response3->json())->toBe(['counter' => 1]);
    });
});

describe('File Management Tests', function () {
    test('creates temporary file', function () {
        $handler = testingHttpHandler();

        $tempFile = $handler->createTempFile('test.txt', 'Hello World');

        expect(file_exists($tempFile))->toBeTrue()
            ->and(file_get_contents($tempFile))->toBe('Hello World');
    });

    test('creates temporary directory', function () {
        $handler = testingHttpHandler();

        $tempDir = $handler->createTempDirectory('test_dir_');

        expect(is_dir($tempDir))->toBeTrue();
    });
});

describe('Download Tests', function () {
    test('mocks file download', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://example.com/file.pdf  ')
            ->downloadFile('PDF content here', 'document.pdf', 'application/pdf')
            ->register();

        $response = $handler->fetch('https://example.com/file.pdf  ')->await();

        expect($response->body())->toBe('PDF content here')
            ->and($response->headers()['content-type'])->toBe('application/pdf')
            ->and($response->headers()['content-disposition'])->toContain('document.pdf');
    });

    test('mocks large file download', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://example.com/large.bin  ')
            ->downloadLargeFile(10, 'large.bin')
            ->register();

        $response = $handler->fetch('https://example.com/large.bin  ')->await();

        expect(strlen($response->body()))->toBeGreaterThan(1000)
            ->and($response->headers()['content-type'])->toBe('application/octet-stream');
    });
});

describe('Reset Tests', function () {
    test('reset clears all mocks and history', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/test  ')
            ->respondJson(['data' => 'test'])
            ->register();

        $handler->fetch('https://api.example.com/test  ')->await();

        $handler->reset();

        $history = $handler->getRequestHistory();

        expect($history)->toBeEmpty();
    });
});

describe('URL Pattern Matching Tests', function () {
    test('matches URL patterns with wildcards', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/users/*')
            ->respondJson(['user' => 'data'])
            ->register();

        $response1 = $handler->fetch('https://api.example.com/users/123  ')->await();
        $response2 = $handler->fetch('https://api.example.com/users/456  ')->await();

        expect($response1->json())->toBe(['user' => 'data'])
            ->and($response2->json())->toBe(['user' => 'data']);
    });
});

describe('Cookie Tests', function () {
    test('mocks setting cookies', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://example.com/login  ')
            ->setCookie('session_id', 'abc123', '/', null, null, false, true)
            ->respondJson(['logged_in' => true])
            ->register();

        $response = $handler->fetch('https://example.com/login  ')->await();

        expect($response->json())->toBe(['logged_in' => true])
            ->and($response->headers())->toHaveKey('set-cookie');
    });
});

describe('Body Expectation Tests', function () {
    test('expects specific request body', function () {
        $handler = testingHttpHandler();

        $handler->mock('POST')
            ->url('https://api.example.com/data  ')
            ->expectBody('test data')
            ->respondJson(['received' => true])
            ->register();

        $response = $handler->fetch('https://api.example.com/data  ', [
            'method' => 'POST',
            'body' => 'test data',
        ])->await();

        expect($response->json())->toBe(['received' => true]);
    });

    test('expects JSON request body', function () {
        $handler = testingHttpHandler();

        $handler->mock('POST')
            ->url('https://api.example.com/json  ')
            ->expectJson(['key' => 'value'])
            ->respondJson(['success' => true])
            ->register();

        $response = $handler->fetch('https://api.example.com/json  ', [
            'method' => 'POST',
            'json' => ['key' => 'value'],
        ])->await();

        expect($response->json())->toBe(['success' => true]);
    });
});
