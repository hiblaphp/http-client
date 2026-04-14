<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Interfaces\RequestInterface as Request;
use Hibla\HttpClient\Interfaces\ResponseInterface;

use function Hibla\asyncFn;
use function Hibla\await;

describe('Interceptor Pipeline Validation', function () {

    beforeEach(function () {
        Http::startTesting();
        Http::mock('GET')->url('*')->respondWithStatus(200)->register();
    });

    afterEach(function () {
        Http::stopTesting();
    });

    describe('withInterceptor()', function () {

        test('throws when callback returns void', function () {
            await(Http::client()
                ->withInterceptor(function (Request $request, callable $next): void {
                    // forgot to return
                })
                ->get('https://example.com'));
        })->throws(LogicException::class);

        test('throws when callback returns wrong type', function () {
            await(Http::client()
                ->withInterceptor(function (Request $request, callable $next) {
                    return 'oops';
                })
                ->get('https://example.com'));
        })->throws(LogicException::class);

        test('throws when async callback returns void', function () {
            await(Http::client()
                ->withInterceptor(asyncFn(function (Request $request, callable $next): void {
                    // forgot to return
                }))
                ->get('https://example.com'));
        })->throws(LogicException::class);
    });

    describe('withRequestInterceptor()', function () {

        test('throws when callback returns void', function () {
            await(Http::client()
                ->withRequestInterceptor(function (Request $request): void {
                    // forgot to return
                })
                ->get('https://example.com'));
        })->throws(LogicException::class);

        test('throws when callback returns wrong type', function () {
            await(Http::client()
                ->withRequestInterceptor(function (Request $request) {
                    return 'oops';
                })
                ->get('https://example.com'));
        })->throws(LogicException::class);

        test('throws when async callback returns void', function () {
            await(Http::client()
                ->withRequestInterceptor(asyncFn(function (Request $request): void {
                    // forgot to return
                }))
                ->get('https://example.com'));
        })->throws(LogicException::class);
    });

    describe('withResponseInterceptor()', function () {

        test('throws when callback returns void', function () {
            await(Http::client()
                ->withResponseInterceptor(function (ResponseInterface $response): void {
                    // forgot to return
                })
                ->get('https://example.com'));
        })->throws(LogicException::class);

        test('throws when callback returns wrong type', function () {
            await(Http::client()
                ->withResponseInterceptor(function (ResponseInterface $response) {
                    return 'oops';
                })
                ->get('https://example.com'));
        })->throws(LogicException::class);

        test('throws when async callback returns void', function () {
            await(Http::client()
                ->withResponseInterceptor(asyncFn(function (ResponseInterface $response): void {
                    // forgot to return
                }))
                ->get('https://example.com'));
        })->throws(LogicException::class);
    });

    describe('happy path', function () {

        test('all interceptors valid returns 200', function () {
            $response = await(Http::client()
                ->withInterceptor(function (Request $request, callable $next) {
                    return $next($request);
                })
                ->withRequestInterceptor(function (Request $request): Request {
                    return $request->withHeader('X-Test', 'hello');
                })
                ->withResponseInterceptor(function (ResponseInterface $response): ResponseInterface {
                    return $response;
                })
                ->get('https://example.com'));

            expect($response->status())->toBe(200);
            Http::assertHeaderSent('X-Test', 'hello');
        });
    });
});
