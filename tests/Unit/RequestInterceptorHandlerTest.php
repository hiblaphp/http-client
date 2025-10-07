<?php

use Hibla\HttpClient\Handlers\HttpHandler;
use Hibla\HttpClient\Handlers\RequestInterceptorHandler;
use Hibla\HttpClient\Request;
use Hibla\Promise\Promise;

test('it processes synchronous interceptors in order', function () {
    $handler = new RequestInterceptorHandler();
    $initialRequest = new Request(new HttpHandler());

    $interceptors = [
        function (Request $request) {
            return $request->withHeader('X-First', '1');
        },
        function (Request $request) {
            return $request->withHeader('X-Second', '2');
        },
    ];

    $finalRequest = $handler->processInterceptors($initialRequest, $interceptors)->await();

    expect($finalRequest->hasHeader('X-First'))->toBeTrue();
    expect($finalRequest->hasHeader('X-Second'))->toBeTrue();
    expect($finalRequest->getHeaderLine('X-First'))->toBe('1');
});

test('it processes asynchronous interceptors in order', function () {
    $handler = new RequestInterceptorHandler();
    $initialRequest = new Request(new HttpHandler());

    $interceptors = [
        function (Request $request) {
            return Promise::resolved($request->withHeader('X-Async-First', '1'));
        },
        function (Request $request) {
            return Promise::resolved($request->withHeader('X-Async-Second', '2'));
        },
    ];

    $finalRequest = $handler->processInterceptors($initialRequest, $interceptors)->await();

    expect($finalRequest->hasHeader('X-Async-First'))->toBeTrue();
    expect($finalRequest->hasHeader('X-Async-Second'))->toBeTrue();
});

test('it processes a mix of synchronous and asynchronous interceptors', function () {
    $handler = new RequestInterceptorHandler();
    $initialRequest = new Request(new HttpHandler());

    $interceptors = [
        function (Request $request) {
            return $request->withHeader('X-Sync-1', 'A');
        },
        function (Request $request) {
            return Promise::resolved($request->withHeader('X-Async-2', 'B'));
        },
        function (Request $request) {
            return $request->withHeader('X-Sync-3', 'C');
        },
    ];

    $finalRequest = $handler->processInterceptors($initialRequest, $interceptors)->await();

    expect($finalRequest->getHeaders())->toHaveKeys(['X-Sync-1', 'X-Async-2', 'X-Sync-3']);
});

test('it rejects the promise if an interceptor throws an exception', function () {
    $handler = new RequestInterceptorHandler();
    $initialRequest = new Request(new HttpHandler());

    $interceptors = [
        function (Request $request) {
            throw new \Exception('Interceptor failed');
        },
    ];

    $promise = $handler->processInterceptors($initialRequest, $interceptors);

    expect(fn() => $promise->await())->toThrow(Exception::class, 'Interceptor failed');
});