<?php

declare(strict_types=1);

use function Hibla\asyncFn;
use function Hibla\await;

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Interfaces\ResponseInterface;
use Hibla\HttpClient\Interfaces\RequestInterface as Request;

describe('Interceptor Pipeline Validation', function () {
    describe('intercept()', function () {

        test('throws when callback returns void', function () {
            await(Http::request()
                ->intercept(function (Request $request, callable $next): void {
                    // forgot to return
                })
                ->get('https://httpbin.org/get'));
        })->throws(
            LogicException::class,
            'Callback passed to intercept() must return a Hibla\Promise\Interfaces\PromiseInterface, got null/void.'
        );

        test('throws when callback returns wrong type', function () {
            await(Http::request()
                ->intercept(function (Request $request, callable $next) {
                    return 'oops';
                })
                ->get('https://httpbin.org/get'));
        })->throws(
            LogicException::class,
            'Callback passed to intercept() must return a Hibla\Promise\Interfaces\PromiseInterface, got string.'
        );

        test('throws when async callback returns void', function () {
            await(Http::request()
                ->intercept(asyncFn(function (Request $request, callable $next): void {
                    // forgot to return
                }))
                ->get('https://httpbin.org/get'));
        })->throws(
            LogicException::class,
            'The Hibla\Promise\Interfaces\PromiseInterface returned by the callback passed to intercept() must resolve to a Hibla\HttpClient\Response instance, got null/void.'
        );
    });

    describe('interceptRequest()', function () {

        test('throws when callback returns void', function () {
            await(Http::request()
                ->interceptRequest(function (Request $request): void {
                    // forgot to return
                })
                ->get('https://httpbin.org/get'));
        })->throws(
            LogicException::class,
            'Callback passed to interceptRequest() must return a Hibla\HttpClient\Interfaces\RequestInterface instance, got null/void.'
        );

        test('throws when callback returns wrong type', function () {
            await(Http::request()
                ->interceptRequest(function (Request $request) {
                    return 'oops';
                })
                ->get('https://httpbin.org/get'));
        })->throws(
            LogicException::class,
            'Callback passed to interceptRequest() must return a Hibla\HttpClient\Interfaces\RequestInterface instance, got string.'
        );

        test('throws when async callback returns void', function () {
            await(Http::request()
                ->interceptRequest(asyncFn(function (Request $request): void {
                    // forgot to return
                }))
                ->get('https://httpbin.org/get'));
        })->throws(
            LogicException::class,
            'The Hibla\Promise\Interfaces\PromiseInterface passed to interceptRequest() must resolve to a Hibla\HttpClient\Interfaces\RequestInterface instance, got null/void.'
        );
    });

    describe('interceptResponse()', function () {

        test('throws when callback returns void', function () {
            await(Http::request()
                ->interceptResponse(function (ResponseInterface $response): void {
                    // forgot to return
                })
                ->get('https://httpbin.org/get'));
        })->throws(
            LogicException::class,
            'Callback passed to interceptResponse() must return a Hibla\HttpClient\Interfaces\ResponseInterface instance, got null/void.'
        );

        test('throws when callback returns wrong type', function () {
            await(Http::request()
                ->interceptResponse(function (ResponseInterface $response) {
                    return 'oops';
                })
                ->get('https://httpbin.org/get'));
        })->throws(
            LogicException::class,
            'Callback passed to interceptResponse() must return a Hibla\HttpClient\Interfaces\ResponseInterface instance, got string.'
        );

        test('throws when async callback returns void', function () {
            await(Http::request()
                ->interceptResponse(asyncFn(function (ResponseInterface $response): void {
                    // forgot to return
                }))
                ->get('https://httpbin.org/get'));
        })->throws(
            LogicException::class,
            'The Hibla\Promise\Interfaces\PromiseInterface passed to interceptResponse() must resolve to a Hibla\HttpClient\Interfaces\ResponseInterface instance, got null/void.'
        );
    });

    describe('happy path', function () {

        test('all interceptors valid returns 200', function () {
            $response = await(Http::request()
                ->intercept(function (Request $request, callable $next) {
                    return $next($request);
                })
                ->interceptRequest(function (Request $request): Request {
                    return $request->withHeader('X-Test', 'hello');
                })
                ->interceptResponse(function (ResponseInterface $response): ResponseInterface {
                    return $response;
                })
                ->get('https://httpbin.org/get'));

            expect($response->status())->toBe(200);
        });
    });
})->skipOnCI();