<?php

use Hibla\HttpClient\CacheConfig;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\CacheManager;
use Hibla\HttpClient\Testing\Utilities\Executors\FetchRequestExecutor;
use Hibla\HttpClient\Testing\Utilities\FileManager;
use Hibla\HttpClient\Testing\Utilities\Handlers\CacheHandler;
use Hibla\HttpClient\Testing\Utilities\NetworkSimulator;
use Hibla\HttpClient\Testing\Utilities\RequestMatcher;
use Hibla\HttpClient\Testing\Utilities\RequestRecorder;
use Hibla\HttpClient\Testing\Utilities\ResponseFactory;
use Hibla\HttpClient\Testing\Utilities\Validators\RequestValidator;
use Hibla\HttpClient\Testing\Exceptions\UnexpectedRequestException;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\Promise\Promise;

function createFetchExcutor(): FetchRequestExecutor
{
    return new FetchRequestExecutor(
        new RequestMatcher(),
        new ResponseFactory(new NetworkSimulator()),
        new FileManager(),
        new RequestRecorder(),
        new CacheHandler(new CacheManager()),
        new RequestValidator()
    );
}

test('executes basic get request', function () {
    $executor = createFetchExcutor();
    $mocks = [];
    
    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/users');
    $mock->setBody('{"users": []}');
    $mocks[] = $mock;

    $result = $executor->execute(
        'https://api.example.com/users',
        ['method' => 'GET'],
        $mocks,
        []
    )->await();

    expect($result)->toBeInstanceOf(Response::class)
        ->and($result->body())->toBe('{"users": []}')
        ->and($mocks)->toBeEmpty();
});

test('executes post request with json body', function () {
    $executor = createFetchExcutor();
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
            'method' => 'POST',
            'body' => json_encode(['name' => 'John']),
            'headers' => ['Content-Type: application/json']
        ],
        $mocks,
        []
    )->await();

    expect($result->status())->toBe(201)
        ->and($result->body())->toBe('{"id": 1, "name": "John"}');
});

test('persistent mock remains available', function () {
    $executor = createFetchExcutor();
    $mocks = [];
    
    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/data');
    $mock->setBody('{"result": "ok"}');
    $mock->setPersistent(true);
    $mocks[] = $mock;

    $executor->execute('https://api.example.com/data', [], $mocks, [])->await();
    expect($mocks)->toHaveCount(1);

    $executor->execute('https://api.example.com/data', [], $mocks, [])->await();
    expect($mocks)->toHaveCount(1);
});

test('non persistent mock is removed', function () {
    $executor = createFetchExcutor();
    $mocks = [];
    
    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/data');
    $mock->setBody('{"result": "ok"}');
    $mocks[] = $mock;

    $executor->execute('https://api.example.com/data', [], $mocks, [])->await();
    expect($mocks)->toBeEmpty();
});

test('executes with custom headers', function () {
    $executor = createFetchExcutor();
    $mocks = [];
    
    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/secure');
    $mock->setBody('{"authenticated": true}');
    $mocks[] = $mock;

    $result = $executor->execute(
        'https://api.example.com/secure',
        [
            'method' => 'GET',
            'headers' => ['Authorization: Bearer token123']
        ],
        $mocks,
        []
    )->await();

    expect($result->body())->toBe('{"authenticated": true}');
});

test('throws exception when no mock matches in strict mode', function () {
    $executor = createFetchExcutor();
    $mocks = [];

    $executor->execute(
        'https://api.example.com/unknown',
        [],
        $mocks,
        ['strict_matching' => true]
    )->await();
})->throws(UnexpectedRequestException::class);

test('allows passthrough when enabled', function () {
    $executor = createFetchExcutor();
    $mocks = [];
    
    $parentFetchCalled = false;
    $parentFetch = function () use (&$parentFetchCalled) {
        $parentFetchCalled = true;
        return Promise::resolved(new Response('passthrough', 200, []));
    };

    $result = $executor->execute(
        'https://api.example.com/passthrough',
        [],
        $mocks,
        ['strict_matching' => false, 'allow_passthrough' => true],
        $parentFetch
    )->await();

    expect($parentFetchCalled)->toBeTrue()
        ->and($result->body())->toBe('passthrough');
});

test('executes request with delay', function () {
    $executor = createFetchExcutor();
    $mocks = [];
    
    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/slow');
    $mock->setDelay(0.1);
    $mock->setBody('{"delayed": true}');
    $mocks[] = $mock;

    $start = microtime(true);
    $result = $executor->execute(
        'https://api.example.com/slow',
        [],
        $mocks,
        []
    )->await();
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeGreaterThanOrEqual(0.1)
        ->and($result->body())->toBe('{"delayed": true}');
});

test('handles error mock', function () {
    $executor = createFetchExcutor();
    $mocks = [];
    
    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/error');
    $mock->setError('Connection failed');
    $mocks[] = $mock;

    $executor->execute(
        'https://api.example.com/error',
        [],
        $mocks,
        []
    )->await();
})->throws(NetworkException::class, 'Connection failed');

test('uses cache config when provided', function () {
    $executor = createFetchExcutor();
    $mocks = [];
    
    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/cached');
    $mock->setBody('{"cached": true}');
    $mocks[] = $mock;

    $cacheConfig = new CacheConfig(ttlSeconds: 60);
    
    $result1 = $executor->execute(
        'https://api.example.com/cached',
        ['cache' => $cacheConfig],
        $mocks,
        []
    )->await();

    expect($mocks)->toBeEmpty();

    $result2 = $executor->execute(
        'https://api.example.com/cached',
        ['cache' => $cacheConfig],
        $mocks,
        []
    )->await();

    expect($result1->body())->toBe($result2->body());
});

test('matches wildcard method', function () {
    $executor = createFetchExcutor();
    $mocks = [];
    
    $mock = new MockedRequest('*');
    $mock->setUrlPattern('https://api.example.com/any');
    $mock->setBody('{"method": "any"}');
    $mocks[] = $mock;

    $result = $executor->execute(
        'https://api.example.com/any',
        ['method' => 'DELETE'],
        $mocks,
        []
    )->await();

    expect($result->body())->toBe('{"method": "any"}');
});

test('handles multiple mocks with first match priority', function () {
    $executor = createFetchExcutor();
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
        [],
        $mocks,
        []
    )->await();

    expect($result->body())->toBe('{"source": "first"}')
        ->and($mocks)->toHaveCount(1);
});