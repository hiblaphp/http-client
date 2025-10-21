<?php

use Hibla\HttpClient\CacheConfig;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\RetryConfig;
use Hibla\HttpClient\Testing\Exceptions\UnexpectedRequestException;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\CacheManager;
use Hibla\HttpClient\Testing\Utilities\CookieManager;
use Hibla\HttpClient\Testing\Utilities\Executors\StandardRequestExecutor;
use Hibla\HttpClient\Testing\Utilities\Handlers\CacheHandler;
use Hibla\HttpClient\Testing\Utilities\NetworkSimulator;
use Hibla\HttpClient\Testing\Utilities\RequestMatcher;
use Hibla\HttpClient\Testing\Utilities\RequestRecorder;
use Hibla\HttpClient\Testing\Utilities\ResponseFactory;
use Hibla\HttpClient\Testing\Utilities\Validators\RequestValidator;
use Hibla\Promise\Promise;

uses()->group('sequential');

function createStandardExecutor(): StandardRequestExecutor
{
    return new StandardRequestExecutor(
        new RequestMatcher(),
        new ResponseFactory(new NetworkSimulator()),
        new CookieManager(),
        new RequestRecorder(),
        new CacheHandler(new CacheManager()),
        new RequestValidator()
    );
}

test('executes basic get request', function () {
    $executor = createStandardExecutor();
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/users');
    $mock->setBody('{"users": []}');
    $mocks[] = $mock;

    $result = $executor->execute(
        'https://api.example.com/users',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        []
    )->await();

    expect($result)->toBeInstanceOf(Response::class)
        ->and($result->body())->toBe('{"users": []}')
        ->and($mocks)->toBeEmpty()
    ;
});

test('executes post request with json body', function () {
    $executor = createStandardExecutor();
    $mocks = [];

    $mock = new MockedRequest('POST');
    $mock->setUrlPattern('https://api.example.com/users');
    $mock->setJsonMatcher(['name' => 'John']);
    $mock->setStatusCode(201);
    $mock->setBody('{"id": 1, "name": "John"}');
    $mocks[] = $mock;

    $result = $executor->execute(
        'https://api.example.com/users',
        [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode(['name' => 'John']),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ],
        $mocks,
        []
    )->await();

    expect($result->status())->toBe(201)
        ->and($result->body())->toBe('{"id": 1, "name": "John"}')
    ;
});

test('persistent mock remains available', function () {
    $executor = createStandardExecutor();
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/data');
    $mock->setBody('{"result": "ok"}');
    $mock->setPersistent(true);
    $mocks[] = $mock;

    $executor->execute(
        'https://api.example.com/data',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        []
    )->await();
    expect($mocks)->toHaveCount(1);

    $executor->execute(
        'https://api.example.com/data',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        []
    )->await();
    expect($mocks)->toHaveCount(1);
});

test('non persistent mock is removed', function () {
    $executor = createStandardExecutor();
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/data');
    $mock->setBody('{"result": "ok"}');
    $mocks[] = $mock;

    $executor->execute(
        'https://api.example.com/data',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        []
    )->await();
    expect($mocks)->toBeEmpty();
});

test('executes with custom headers', function () {
    $executor = createStandardExecutor();
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/secure');
    $mock->setBody('{"authenticated": true}');
    $mocks[] = $mock;

    $result = $executor->execute(
        'https://api.example.com/secure',
        [
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => ['Authorization: Bearer token123'],
        ],
        $mocks,
        []
    )->await();

    expect($result->body())->toBe('{"authenticated": true}');
});

test('throws exception when no mock matches and passthrough disabled', function () {
    $executor = createStandardExecutor();
    $mocks = [];

    $executor->execute(
        'https://api.example.com/unknown',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        ['allow_passthrough' => false]
    )->await();
})->throws(UnexpectedRequestException::class);

test('allows passthrough when enabled', function () {
    $executor = createStandardExecutor();
    $mocks = [];

    $parentSendCalled = false;
    $parentSend = function () use (&$parentSendCalled) {
        $parentSendCalled = true;

        return Promise::resolved(new Response('passthrough', 200, []));
    };

    $result = $executor->execute(
        'https://api.example.com/passthrough',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        ['allow_passthrough' => true],
        null,
        null,
        $parentSend
    )->await();

    expect($parentSendCalled)->toBeTrue()
        ->and($result->body())->toBe('passthrough')
    ;
});

test('executes request with delay', function () {
    $executor = createStandardExecutor();
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/slow');
    $mock->setDelay(0.1);
    $mock->setBody('{"delayed": true}');
    $mocks[] = $mock;

    $start = microtime(true);
    $result = $executor->execute(
        'https://api.example.com/slow',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        []
    )->await();
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeGreaterThanOrEqual(0.1)
        ->and($result->body())->toBe('{"delayed": true}')
    ;
});

test('handles error mock', function () {
    $executor = createStandardExecutor();
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/error');
    $mock->setError('Connection failed');
    $mocks[] = $mock;

    $executor->execute(
        'https://api.example.com/error',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        []
    )->await();
})->throws(NetworkException::class, 'Connection failed');

test('uses cache config when provided', function () {
    $executor = createStandardExecutor();
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/cached');
    $mock->setBody('{"cached": true}');
    $mocks[] = $mock;

    $cacheConfig = new CacheConfig(ttlSeconds: 60);

    $result1 = $executor->execute(
        'https://api.example.com/cached',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        [],
        $cacheConfig
    )->await();

    expect($mocks)->toBeEmpty();

    $result2 = $executor->execute(
        'https://api.example.com/cached',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        [],
        $cacheConfig
    )->await();

    expect($result1->body())->toBe($result2->body());
});

test('matches wildcard method', function () {
    $executor = createStandardExecutor();
    $mocks = [];

    $mock = new MockedRequest('*');
    $mock->setUrlPattern('https://api.example.com/any');
    $mock->setBody('{"method": "any"}');
    $mocks[] = $mock;

    $result = $executor->execute(
        'https://api.example.com/any',
        [CURLOPT_CUSTOMREQUEST => 'DELETE'],
        $mocks,
        []
    )->await();

    expect($result->body())->toBe('{"method": "any"}');
});

test('handles multiple mocks with first match priority', function () {
    $executor = createStandardExecutor();
    $mocks = [];

    $mock1 = new MockedRequest('GET');
    $mock1->setUrlPattern('https://api.example.com/data');
    $mock1->setBody('{"source": "first"}');
    $mocks[] = $mock1;

    $mock2 = new MockedRequest('GET');
    $mock2->setUrlPattern('https://api.example.com/data');
    $mock2->setBody('{"source": "second"}');
    $mocks[] = $mock2;

    $result = $executor->execute(
        'https://api.example.com/data',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        []
    )->await();

    expect($result->body())->toBe('{"source": "first"}')
        ->and($mocks)->toHaveCount(1)
    ;
});

test('defaults to GET method when not specified', function () {
    $executor = createStandardExecutor();
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/default');
    $mock->setBody('{"default": "GET"}');
    $mocks[] = $mock;

    $result = $executor->execute(
        'https://api.example.com/default',
        [], // No CURLOPT_CUSTOMREQUEST specified
        $mocks,
        []
    )->await();

    expect($result->body())->toBe('{"default": "GET"}');
});

test('executes with retry config', function () {
    $executor = createStandardExecutor();
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/retry');
    $mock->setBody('{"retried": true}');
    $mocks[] = $mock;

    $retryConfig = new RetryConfig(maxRetries: 3, baseDelay: 0.1);

    $result = $executor->execute(
        'https://api.example.com/retry',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        [],
        null,
        $retryConfig
    )->await();

    expect($result->body())->toBe('{"retried": true}');
});

test('processes cookies from response', function () {
    $executor = createStandardExecutor();
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/with-cookies');
    $mock->setBody('{"has_cookies": true}');
    $mock->addResponseHeader('Set-Cookie', 'session=abc123');
    $mocks[] = $mock;

    $result = $executor->execute(
        'https://api.example.com/with-cookies',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        []
    )->await();

    expect($result->body())->toBe('{"has_cookies": true}');
});
