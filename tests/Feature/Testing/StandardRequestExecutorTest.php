<?php

declare(strict_types=1);

use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\Testing\Exceptions\UnexpectedRequestException;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\CookieManager;
use Hibla\HttpClient\Testing\Utilities\Executors\StandardRequestExecutor;
use Hibla\HttpClient\Testing\Utilities\FileManager;
use Hibla\HttpClient\Testing\Utilities\Handlers\ResponseTypeHandler;
use Hibla\HttpClient\Testing\Utilities\NetworkSimulator;
use Hibla\HttpClient\Testing\Utilities\RequestMatcher;
use Hibla\HttpClient\Testing\Utilities\RequestRecorder;
use Hibla\HttpClient\Testing\Utilities\ResponseFactory;
use Hibla\HttpClient\Testing\Utilities\Validators\RequestValidator;
use Hibla\HttpClient\ValueObjects\RetryConfig;
use Hibla\Promise\Promise;

uses()->group('sequential');

function createStandardTestExecutor(): StandardRequestExecutor
{
    $responseFactory = new ResponseFactory(new NetworkSimulator());
    $fileManager = new FileManager();

    return new StandardRequestExecutor(
        new RequestMatcher(),
        $responseFactory,
        new CookieManager(),
        new RequestRecorder(),
        new RequestValidator(),
        new ResponseTypeHandler($responseFactory, $fileManager)
    );
}

test('executes basic get request', function () {
    $executor = createStandardTestExecutor();
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
    )->wait();

    expect($result)->toBeInstanceOf(Response::class)
        ->and($result->body())->toBe('{"users": []}')
        ->and($mocks)->toBeEmpty()
    ;
});

test('executes post request with json body', function () {
    $executor = createStandardTestExecutor();
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
    )->wait();

    expect($result->status())->toBe(201)
        ->and($result->body())->toBe('{"id": 1, "name": "John"}')
    ;
});

test('persistent mock remains available', function () {
    $executor = createStandardTestExecutor();
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
    )->wait();
    expect($mocks)->toHaveCount(1);

    $executor->execute(
        'https://api.example.com/data',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        []
    )->wait();
    expect($mocks)->toHaveCount(1);
});

test('non persistent mock is removed', function () {
    $executor = createStandardTestExecutor();
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
    )->wait();
    expect($mocks)->toBeEmpty();
});

test('executes with custom headers', function () {
    $executor = createStandardTestExecutor();
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
    )->wait();

    expect($result->body())->toBe('{"authenticated": true}');
});

test('throws exception when no mock matches and passthrough disabled', function () {
    $executor = createStandardTestExecutor();
    $mocks = [];

    $executor->execute(
        'https://api.example.com/unknown',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        ['allow_passthrough' => false]
    )->wait();
})->throws(UnexpectedRequestException::class);

test('allows passthrough when enabled', function () {
    $executor = createStandardTestExecutor();
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
        $parentSend
    )->wait();

    expect($parentSendCalled)->toBeTrue()
        ->and($result->body())->toBe('passthrough')
    ;
});

test('executes request with delay', function () {
    $executor = createStandardTestExecutor();
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
    )->wait();
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeGreaterThanOrEqual(0.1)
        ->and($result->body())->toBe('{"delayed": true}')
    ;
});

test('handles error mock', function () {
    $executor = createStandardTestExecutor();
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
    )->wait();
})->throws(NetworkException::class, 'Connection failed');

test('matches wildcard method', function () {
    $executor = createStandardTestExecutor();
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
    )->wait();

    expect($result->body())->toBe('{"method": "any"}');
});

test('handles multiple mocks with first match priority', function () {
    $executor = createStandardTestExecutor();
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
    )->wait();

    expect($result->body())->toBe('{"source": "first"}')
        ->and($mocks)->toHaveCount(1)
    ;
});

test('defaults to GET method when not specified', function () {
    $executor = createStandardTestExecutor();
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
    )->wait();

    expect($result->body())->toBe('{"default": "GET"}');
});

test('executes with retry config', function () {
    $executor = createStandardTestExecutor();
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
        $retryConfig
    )->wait();

    expect($result->body())->toBe('{"retried": true}');
});

test('processes cookies from response', function () {
    $executor = createStandardTestExecutor();
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
    )->wait();

    expect($result->body())->toBe('{"has_cookies": true}');
});
