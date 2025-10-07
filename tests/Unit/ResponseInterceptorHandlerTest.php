<?php

use Hibla\HttpClient\Handlers\ResponseInterceptorHandler;
use Hibla\HttpClient\Response;
use Hibla\Promise\Promise;

test('it processes synchronous interceptors in order', function () {
    $handler = new ResponseInterceptorHandler();
    $initialResponse = new Response();

    $interceptors = [
        function (Response $response) {
            return $response->withHeader('X-First', '1');
        },
        function (Response $response) {
            return $response->withHeader('X-Second', '2');
        },
    ];

    $finalResponse = $handler->processInterceptors($initialResponse, $interceptors)->await();

    expect($finalResponse->hasHeader('X-First'))->toBeTrue();
    expect($finalResponse->hasHeader('X-Second'))->toBeTrue();
    expect($finalResponse->getHeaderLine('X-First'))->toBe('1');
});

test('it processes asynchronous interceptors in order', function () {
    $handler = new ResponseInterceptorHandler();
    $initialResponse = new Response();

    $interceptors = [
        function (Response $response) {
            return Promise::resolved($response->withHeader('X-Async-First', '1'));
        },
        function (Response $response) {
            return Promise::resolved($response->withHeader('X-Async-Second', '2'));
        },
    ];

    $finalResponse = $handler->processInterceptors($initialResponse, $interceptors)->await();

    expect($finalResponse->hasHeader('X-Async-First'))->toBeTrue();
    expect($finalResponse->hasHeader('X-Async-Second'))->toBeTrue();
});

test('it processes a mix of synchronous and asynchronous interceptors', function () {
    $handler = new ResponseInterceptorHandler();
    $initialResponse = new Response();

    $interceptors = [
        function (Response $response) {
            return $response->withHeader('X-Sync-1', 'A');
        },
        function (Response $response) {
            return Promise::resolved($response->withHeader('X-Async-2', 'B'));
        },
        function (Response $response) {
            return $response->withHeader('X-Sync-3', 'C');
        },
    ];

    $finalResponse = $handler->processInterceptors($initialResponse, $interceptors)->await();
    
    expect($finalResponse->getHeaders())->toHaveKeys(['X-Sync-1', 'X-Async-2', 'X-Sync-3']);
});

test('it rejects the promise if an interceptor throws an exception', function () {
    $handler = new ResponseInterceptorHandler();
    $initialResponse = new Response();

    $interceptors = [
        function (Response $response) {
            throw new \Exception('Interceptor failed');
        },
    ];

    $promise = $handler->processInterceptors($initialResponse, $interceptors);

    expect(fn() => $promise->await())->toThrow(Exception::class, 'Interceptor failed');
});