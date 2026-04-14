<?php

declare(strict_types=1);

namespace Tests\Integration;

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Interfaces\RequestInterface;
use Hibla\HttpClient\Interfaces\ResponseInterface;
use Hibla\HttpClient\SSE\SSEResponse;
use Tests\Fixtures\HttpBin;

use function Hibla\delay;

beforeEach(function () {
    HttpBin::skipIfUnreachable();
    Http::startTesting()->enablePassthrough();
});

afterEach(function () {
    Http::stopTesting();
});

describe('SSE Interceptor Integration (Real Network + Assertions)', function () {

    it('allows interceptRequest to add headers to a real SSE connection', function () {
        $promise = Http::client()
            ->withRequestInterceptor(function (RequestInterface $request) {
                return $request->withHeader('X-Real-SSE-Auth', 'passthrough-key-456');
            })
            ->sse(HttpBin::url('/get'))
            ->connect()
        ;

        $response = $promise->wait();

        expect($response)->toBeInstanceOf(SSEResponse::class);

        Http::assertHeaderSent('X-Real-SSE-Auth', 'passthrough-key-456');
        Http::assertHeaderSent('Accept', 'text/event-stream');
    });

    it('allows interceptResponse to inspect real headers from HttpBin', function () {
        $capturedHeader = null;

        $promise = Http::client()
            ->withResponseInterceptor(function (ResponseInterface $response) use (&$capturedHeader) {
                $capturedHeader = $response->header('Content-Type');

                return $response;
            })
            ->sse(HttpBin::url('/get'))
            ->connect()
        ;

        $promise->wait();

        expect($capturedHeader)->not->toBeNull()
            ->and($capturedHeader)->toContain('application/json')
        ;
    });

    it('handles asynchronous interceptors before establishing real SSE connection', function () {
        $startTime = microtime(true);

        $promise = Http::client()
            ->withRequestInterceptor(function (RequestInterface $request) {
                return delay(0.1)->then(fn () => $request->withHeader('X-Async-Passthrough', 'true'));
            })
            ->sse(HttpBin::url('/get'))
            ->connect()
        ;

        $promise->wait();
        $duration = microtime(true) - $startTime;

        expect($duration)->toBeGreaterThanOrEqual(0.1);

        Http::assertHeaderSent('X-Async-Passthrough', 'true');
    });

    it('successfully processes full pipeline interceptors with real traffic', function () {
        $steps = [];

        $promise = Http::client()
            ->withInterceptor(function (RequestInterface $request, callable $next) use (&$steps) {
                $steps[] = 'before-network';

                return $next($request)->then(function (ResponseInterface $response) use (&$steps) {
                    $steps[] = 'after-network';

                    return $response;
                });
            })
            ->sse(HttpBin::url('/get'))
            ->connect()
        ;

        $promise->wait();

        expect($steps)->toBe(['before-network', 'after-network']);

        Http::assertRequestCount(1);
    });
});
