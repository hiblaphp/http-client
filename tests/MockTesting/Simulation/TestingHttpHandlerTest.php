<?php

declare(strict_types=1);

use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\HttpClient;

afterEach(function () {
    testingHttpHandler()->reset();
});

describe('Basic Mock Response Tests', function () {
    test('mock responds with custom status code', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/users')
            ->respondWithStatus(201)
            ->respondJson(['id' => 1, 'name' => 'John'])
            ->register()
        ;

        $response = (new HttpClient())
            ->setHandler($handler)
            ->get('https://api.example.com/users')
            ->wait()
        ;

        expect($response->status())->toBe(201)
            ->and($response->json())->toBe(['id' => 1, 'name' => 'John'])
        ;
    });

    test('mock responds with json data', function () {
        $handler = testingHttpHandler();

        $handler->mock('POST')
            ->url('https://api.example.com/posts')
            ->respondJson(['success' => true, 'post_id' => 123])
            ->register()
        ;

        $response = (new HttpClient())
            ->setHandler($handler)
            ->withJson(['title' => 'Test Post'])
            ->post('https://api.example.com/posts')
            ->wait()
        ;

        expect($response->json())->toBe(['success' => true, 'post_id' => 123])
            ->and($response->header('content-type'))->toContain('application/json')
        ;
    });

    test('mock responds with plain text', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://example.com/text')
            ->respondWith('Hello World')
            ->register()
        ;

        $response = (new HttpClient())
            ->setHandler($handler)
            ->get('https://example.com/text')
            ->wait()
        ;

        expect($response->body())->toBe('Hello World');
    });
});

describe('Delay Simulation Tests', function () {
    test('mock applies fixed delay', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/slow')
            ->delay(0.5)
            ->respondJson(['data' => 'slow response'])
            ->register()
        ;

        $start = microtime(true);
        $response = (new HttpClient())
            ->setHandler($handler)
            ->get('https://api.example.com/slow')
            ->wait()
        ;
        $duration = microtime(true) - $start;

        expect($duration)->toBeGreaterThanOrEqual(0.5)
            ->and($response->json())->toBe(['data' => 'slow response'])
        ;
    });

    test('mock applies random delay within range', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/random-slow')
            ->randomDelay(0.2, 0.4)
            ->respondJson(['data' => 'random delay response'])
            ->register()
        ;

        $start = microtime(true);
        $response = (new HttpClient())
            ->setHandler($handler)
            ->get('https://api.example.com/random-slow')
            ->wait()
        ;
        $duration = microtime(true) - $start;

        expect($duration)->toBeGreaterThanOrEqual(0.2)
            ->and($duration)->toBeLessThanOrEqual(0.6) // Margin for event loop ticks
        ;
    });

    test('global random delay affects all requests', function () {
        $handler = testingHttpHandler();
        $handler->withGlobalRandomDelay(0.1, 0.2);

        $handler->mock('GET')
            ->url('https://api.example.com/test')
            ->respondJson(['result' => 'ok'])
            ->register()
        ;

        $start = microtime(true);
        $response = (new HttpClient())
            ->setHandler($handler)
            ->get('https://api.example.com/test')
            ->wait()
        ;
        $duration = microtime(true) - $start;

        expect($duration)->toBeGreaterThanOrEqual(0.1);
    });
});

describe('Error Simulation Tests', function () {
    test('mock simulates connection failure', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/fail')
            ->fail('Connection refused')
            ->register()
        ;

        expect(fn () => (new HttpClient())->setHandler($handler)->get('https://api.example.com/fail')->wait())
            ->toThrow(NetworkException::class)
        ;
    });

    test('mock simulates timeout', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/timeout')
            ->timeout(0.1)
            ->register()
        ;

        expect(fn () => (new HttpClient())->setHandler($handler)->get('https://api.example.com/timeout')->wait())
            ->toThrow(NetworkException::class)
        ;
    });

    test('mock simulates network error', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/network-error')
            ->networkError('connection')
            ->register()
        ;

        expect(fn () => (new HttpClient())->setHandler($handler)->get('https://api.example.com/network-error')->wait())
            ->toThrow(NetworkException::class)
        ;
    });
});

describe('Retry Sequence Tests', function () {
    test('fails until specified attempt then succeeds', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/retry')
            ->failUntilAttempt(3, 'Temporary failure')
            ->register()
        ;

        $response = (new HttpClient())
            ->setHandler($handler)
            ->retry(5, 0.01)
            ->get('https://api.example.com/retry')
            ->wait()
        ;

        expect($response->json())->toHaveKey('success', true)
            ->and($response->json())->toHaveKey('attempt', 3)
        ;
    });

    test('timeout until specified attempt', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/timeout-retry')
            ->timeoutUntilAttempt(2, 0.1)
            ->register()
        ;

        $response = (new HttpClient())
            ->setHandler($handler)
            ->retry(5, 0.01)
            ->get('https://api.example.com/timeout-retry')
            ->wait()
        ;

        expect($response->json())->toHaveKey('success', true);
    });

    test('fails with custom sequence', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/sequence')
            ->failWithSequence([
                'First error',
                ['error' => 'Second error', 'delay' => 0.05],
                ['error' => 'Third error', 'retryable' => true],
            ], ['final' => 'success'])
            ->register()
        ;

        $response = (new HttpClient())
            ->setHandler($handler)
            ->retry(5, 0.01)
            ->get('https://api.example.com/sequence')
            ->wait()
        ;

        expect($response->json())->toBe(['final' => 'success']);
    });
});

describe('Advanced Scenario Tests', function () {
    test('simulates rate limiting', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/rate-limited')
            ->rateLimitedUntilAttempt(2)
            ->register()
        ;

        $response = (new HttpClient())
            ->setHandler($handler)
            ->retry(5, 0.01)
            ->get('https://api.example.com/rate-limited')
            ->wait()
        ;

        expect($response->status())->toBe(200)
            ->and($response->json())->toHaveKey('success', true)
        ;
    });

    test('simulates gradually improving network', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/improving')
            ->failUntilAttempt(2)
            ->respondJson(['success' => true])
            ->persistent()
            ->register()
        ;

        $response = (new HttpClient())
            ->setHandler($handler)
            ->retry(5, 0.01)
            ->get('https://api.example.com/improving')
            ->wait()
        ;

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
            ->register()
        ;

        $start = microtime(true);

        try {
            (new HttpClient())->setHandler($handler)->get('https://api.example.com/test')->wait();
        } catch (Exception $e) {
            // expected
        }

        $duration = microtime(true) - $start;
        expect($duration)->toBeGreaterThan(0.05);
    });

    test('enables fast network simulation', function () {
        $handler = testingHttpHandler();
        $handler->withFastNetwork();

        $handler->mock('GET')
            ->url('https://api.example.com/test')
            ->respondJson(['data' => 'test'])
            ->register()
        ;

        $start = microtime(true);
        (new HttpClient())->setHandler($handler)->get('https://api.example.com/test')->wait();
        $duration = microtime(true) - $start;

        expect($duration)->toBeLessThan(1.0);
    });
});

describe('Header Tests', function () {
    test('mock returns custom response headers', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/headers')
            ->respondWithHeaders([
                'X-Custom-Header' => 'custom-value',
                'X-Rate-Limit' => '100',
            ])
            ->register()
        ;

        $response = (new HttpClient())
            ->setHandler($handler)
            ->get('https://api.example.com/headers')
            ->wait()
        ;

        expect($response->header('x-custom-header'))->toBe('custom-value')
            ->and($response->header('x-rate-limit'))->toBe('100')
        ;
    });

    test('expects specific request headers', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/auth')
            ->expectHeader('Authorization', 'Bearer token123')
            ->respondJson(['authenticated' => true])
            ->register()
        ;

        $response = (new HttpClient())
            ->setHandler($handler)
            ->withToken('token123')
            ->get('https://api.example.com/auth')
            ->wait()
        ;

        expect($response->json())->toBe(['authenticated' => true]);
    });
});

describe('Request Recording Tests', function () {
    test('records request history', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')->url('https://api.example.com/test1')->respondWithStatus(200)->register();
        $handler->mock('POST')->url('https://api.example.com/test2')->respondWithStatus(200)->register();

        $client = (new HttpClient())->setHandler($handler);
        $client->get('https://api.example.com/test1')->wait();
        $client->post('https://api.example.com/test2')->wait();

        $history = $handler->getRequestHistory();

        expect($history)->toHaveCount(2)
            ->and($history[0]->url)->toContain('test1')
            ->and($history[1]->url)->toContain('test2')
        ;
    });

    test('disables request recording', function () {
        $handler = testingHttpHandler();
        $handler->setRecordRequests(false);

        $handler->mock('GET')->url('https://api.example.com/test')->respondWithStatus(200)->register();

        (new HttpClient())->setHandler($handler)->get('https://api.example.com/test')->wait();

        expect($handler->getRequestHistory())->toBeEmpty();
    });
});

describe('Persistent Mock Tests', function () {
    test('persistent mock handles multiple requests', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/persistent')
            ->respondJson(['counter' => 1])
            ->persistent()
            ->register()
        ;

        $client = (new HttpClient())->setHandler($handler);

        $client->get('https://api.example.com/persistent')->wait();
        $client->get('https://api.example.com/persistent')->wait();
        $response = $client->get('https://api.example.com/persistent')->wait();

        expect($response->json())->toBe(['counter' => 1])
            ->and($handler->getRequestHistory())->toHaveCount(3)
        ;
    });
});

describe('Download Tests', function () {
    test('mocks file download', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://example.com/file.pdf')
            ->downloadFile('PDF content here', 'document.pdf', 'application/pdf')
            ->register()
        ;

        $response = (new HttpClient())
            ->setHandler($handler)
            ->get('https://example.com/file.pdf')
            ->wait()
        ;

        expect($response->body())->toBe('PDF content here')
            ->and($response->header('content-type'))->toBe('application/pdf')
        ;
    });
});

describe('URL Pattern Matching Tests', function () {
    test('matches URL patterns with wildcards', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://api.example.com/users/*')
            ->respondJson(['user' => 'data'])
            ->persistent()
            ->register()
        ;

        $client = (new HttpClient())->setHandler($handler);

        $res1 = $client->get('https://api.example.com/users/123')->wait();
        $res2 = $client->get('https://api.example.com/users/456')->wait();

        expect($res1->json())->toBe(['user' => 'data'])
            ->and($res2->json())->toBe(['user' => 'data'])
        ;
    });
});

describe('Cookie Tests', function () {
    test('mocks setting cookies', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://example.com/login')
            ->setCookie('session_id', 'abc123')
            ->respondJson(['logged_in' => true])
            ->register()
        ;

        $response = (new HttpClient())
            ->setHandler($handler)
            ->get('https://example.com/login')
            ->wait()
        ;

        expect($response->header('set-cookie'))->toContain('session_id=abc123');
    });
});

describe('Body Expectation Tests', function () {
    test('expects specific request body', function () {
        $handler = testingHttpHandler();

        $handler->mock('POST')
            ->url('https://api.example.com/data')
            ->expectBody('test data')
            ->respondJson(['received' => true])
            ->register()
        ;

        $response = (new HttpClient())
            ->setHandler($handler)
            ->body('test data')
            ->post('https://api.example.com/data')
            ->wait()
        ;

        expect($response->json())->toBe(['received' => true]);
    });

    test('expects JSON request body', function () {
        $handler = testingHttpHandler();

        $handler->mock('POST')
            ->url('https://api.example.com/json')
            ->expectJson(['key' => 'value'])
            ->respondJson(['success' => true])
            ->register()
        ;

        $response = (new HttpClient())
            ->setHandler($handler)
            ->withJson(['key' => 'value'])
            ->post('https://api.example.com/json')
            ->wait()
        ;

        expect($response->json())->toBe(['success' => true]);
    });
});
