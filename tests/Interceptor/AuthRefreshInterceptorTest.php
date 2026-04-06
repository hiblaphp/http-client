<?php

declare(strict_types=1);

namespace Tests\Integration;

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Interfaces\RequestInterface;
use Hibla\HttpClient\Interfaces\ResponseInterface;
use Tests\Fixtures\HttpBin;

use function Hibla\delay;

beforeEach(function () {
    HttpBin::skipIfUnreachable();
    Http::startTesting()->enablePassthrough();
});

afterEach(function () {
    Http::stopTesting();
});

describe('Real-world: Token Refresh Interceptor', function () {

    it('pauses and refreshes an expired token seamlessly', function () {
        $refreshCount = 0;

        $client = Http::request()
            ->intercept(function (RequestInterface $request, callable $next) use (&$refreshCount) {
                return $next($request)->then(function (ResponseInterface $response) use ($request, $next, &$refreshCount) {
                    
                    if ($response->status() === 401) {
                        $refreshCount++;

                        \Hibla\await(delay(0.05));
                        $newToken = 'fresh-token-' . $refreshCount;

                        return $next($request->withToken($newToken));
                    }

                    return $response;
                });
            });

        $response = $client->get(HttpBin::url('/bearer'))->wait();

        expect($response->status())->toBe(200)
            ->and($response->json('authenticated'))->toBeTrue()
            ->and($response->json('token'))->toBe('fresh-token-1')
            ->and($refreshCount)->toBe(1);

        Http::assertRequestCount(2);
    });

    it('can globally sign every outgoing request asynchronously', function () {
        $client = Http::request()
            ->interceptRequest(function (RequestInterface $request) {
                return delay(0.01)->then(fn() => $request->withHeader('X-Signature', 'hash-' . md5($request->getUri()->getPath())));
            });

        $res1 = $client->get(HttpBin::url('/get'))->wait();
        $res2 = $client->get(HttpBin::url('/headers'))->wait();

        expect($res1->json('headers.X-Signature.0'))->toBe('hash-' . md5('/get'))
            ->and($res2->json('headers.X-Signature.0'))->toBe('hash-' . md5('/headers'));
    });
});