<?php

declare(strict_types=1);

use Hibla\HttpClient\Response;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\HttpClient\Stream;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\ValueObjects\RetryConfig;
use Hibla\Promise\Promise;

test('executes standard send request', function () {
    $executor = createRequestExecutor();
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/data');
    $mock->setBody('{"result": "success"}');
    $mocks[] = $mock;

    $result = $executor->executeSendRequest(
        'https://api.example.com/data',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        []
    )->wait();

    expect($result)->toBeInstanceOf(Response::class);
});

test('executes SSE request', function () {
    $executor = createRequestExecutor();
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/events');
    $mock->asSSE();
    $mock->setSSEEvents([
        ['event' => 'message', 'data' => 'Hello'],
    ]);
    $mocks[] = $mock;

    $result = $executor->executeSSE(
        'https://api.example.com/events',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        []
    )->wait();

    expect($result)->toBeInstanceOf(SSEResponse::class);
});

test('executes send request with retry config', function () {
    $executor = createRequestExecutor();
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/retry');
    $mock->setBody('{"retry": true}');
    $mocks[] = $mock;

    $retryConfig = new RetryConfig(maxRetries: 3);

    $result = $executor->executeSendRequest(
        'https://api.example.com/retry',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        [],
        $retryConfig
    )->wait();

    expect($result)->toBeInstanceOf(Response::class);
});

test('executes SSE with reconnect config', function () {
    $executor = createRequestExecutor();
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/stream');
    $mock->asSSE();
    $mock->setSSEEvents([
        ['event' => 'message', 'data' => 'Reconnectable'],
    ]);
    $mocks[] = $mock;

    $reconnectConfig = new SSEReconnectConfig(
        enabled: true,
        maxAttempts: 3
    );

    $result = $executor->executeSSE(
        'https://api.example.com/stream',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        [],
        null,
        null,
        null,
        $reconnectConfig
    )->wait();

    expect($result)->toBeInstanceOf(SSEResponse::class);
});

test('executes send request with parent callback', function () {
    $executor = createRequestExecutor();
    $mocks = [];

    $globalSettings = [
        'strict_matching' => false,
        'allow_passthrough' => true,
    ];

    $parentCalled = false;
    $parentSendRequest = function () use (&$parentCalled) {
        $parentCalled = true;

        return new Promise(function ($resolve) {
            $resolve(new Response('{"passthrough": true}', 200, []));
        });
    };

    $result = $executor->executeSendRequest(
        'https://api.example.com/passthrough',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        $globalSettings,
        null,
        $parentSendRequest
    )->wait();

    expect($result)->toBeInstanceOf(Response::class)
        ->and($parentCalled)->toBeTrue()
    ;
});

test('executes SSE with parent callback', function () {
    $executor = createRequestExecutor();
    $mocks = [];

    $globalSettings = [
        'strict_matching' => false,
        'allow_passthrough' => true,
    ];

    $parentCalled = false;
    $parentSSE = function ($url, $options, $onEvent, $onError, $reconnectConfig) use (&$parentCalled) {
        $parentCalled = true;
        $resource = fopen('php://memory', 'r');
        $stream = new Stream($resource);
        $response = new SSEResponse($stream, 200, []);

        return new Promise(function ($resolve, $reject) use ($response) {
            $resolve($response);
        });
    };

    $result = $executor->executeSSE(
        'https://api.example.com/stream',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        $globalSettings,
        null,
        null,
        $parentSSE
    )->wait();

    expect($result)->toBeInstanceOf(SSEResponse::class)
        ->and($parentCalled)->toBeTrue()
    ;
});
