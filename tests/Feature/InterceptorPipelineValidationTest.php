<?php

declare(strict_types=1);

use function Hibla\asyncFn;
use function Hibla\await;

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Interfaces\ResponseInterface;
use Hibla\HttpClient\Interfaces\RequestInterface as Request;

describe('Interceptor Pipeline Validation', function () {
    
    beforeEach(function () {
        Http::startTesting();
        Http::mock('GET')->url('*')->respondWithStatus(200)->register();
    });

    afterEach(function () {
        Http::stopTesting();
    });

    describe('intercept()', function () {

        test('throws when callback returns void', function () {
            await(Http::request()
                ->intercept(function (Request $request, callable $next): void {
                    // forgot to return
                })
                ->get('https://example.com'));
        })->throws(LogicException::class);

        test('throws when callback returns wrong type', function () {
            await(Http::request()
                ->intercept(function (Request $request, callable $next) {
                    return 'oops';
                })
                ->get('https://example.com'));
        })->throws(LogicException::class);

        test('throws when async callback returns void', function () {
            await(Http::request()
                ->intercept(asyncFn(function (Request $request, callable $next): void {
                    // forgot to return
                }))
                ->get('https://example.com'));
        })->throws(LogicException::class);
    });

    describe('interceptRequest()', function () {

        test('throws when callback returns void', function () {
            await(Http::request()
                ->interceptRequest(function (Request $request): void {
                    // forgot to return
                })
                ->get('https://example.com'));
        })->throws(LogicException::class);

        test('throws when callback returns wrong type', function () {
            await(Http::request()
                ->interceptRequest(function (Request $request) {
                    return 'oops';
                })
                ->get('https://example.com'));
        })->throws(LogicException::class);

        test('throws when async callback returns void', function () {
            await(Http::request()
                ->interceptRequest(asyncFn(function (Request $request): void {
                    // forgot to return
                }))
                ->get('https://example.com'));
        })->throws(LogicException::class);
    });

    describe('interceptResponse()', function () {

        test('throws when callback returns void', function () {
            await(Http::request()
                ->interceptResponse(function (ResponseInterface $response): void {
                    // forgot to return
                })
                ->get('https://example.com'));
        })->throws(LogicException::class);

        test('throws when callback returns wrong type', function () {
            await(Http::request()
                ->interceptResponse(function (ResponseInterface $response) {
                    return 'oops';
                })
                ->get('https://example.com'));
        })->throws(LogicException::class);

        test('throws when async callback returns void', function () {
            await(Http::request()
                ->interceptResponse(asyncFn(function (ResponseInterface $response): void {
                    // forgot to return
                }))
                ->get('https://example.com'));
        })->throws(LogicException::class);
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
                ->get('https://example.com'));

            expect($response->status())->toBe(200);
            Http::assertHeaderSent('X-Test', 'hello');
        });
    });
});