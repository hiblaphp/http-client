<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Interfaces\RequestInterface;
use Hibla\HttpClient\Interfaces\ResponseInterface;
use Hibla\HttpClient\Response;
use Tests\Fixtures\HttpBin;

use function Hibla\await;
use function Hibla\delay;

beforeEach(function () {
    HttpBin::skipIfUnreachable();
});

describe('Interceptor Pipeline', function () {

    it('can modify request headers via interceptRequest', function () {
        $response = Http::request()
            ->interceptRequest(fn (RequestInterface $r) => $r->withHeader('X-Custom-Req', 'hibla-power'))
            ->get(HttpBin::url('/headers'))
            ->wait()
        ;

        expect($response->json('headers.X-Custom-Req.0'))->toBe('hibla-power');
    });

    it('can modify response via interceptResponse', function () {
        $response = Http::request()
            ->interceptResponse(fn (ResponseInterface $res) => $res->withHeader('X-Intercepted-By', 'unit-test'))
            ->get(HttpBin::url('/get'))
            ->wait()
        ;

        expect($response->header('X-Intercepted-By'))->toBe('unit-test');
    });

    it('supports asynchronous request interceptors (e.g. Token Refresh)', function () {
        $response = Http::request()
            ->interceptRequest(function (RequestInterface $r) {
                return delay(0.05)->then(fn () => $r->withHeader('Authorization', 'Bearer async-token-123'));
            })
            ->get(HttpBin::url('/headers'))
            ->wait()
        ;

        expect($response->json('headers.Authorization.0'))->toBe('Bearer async-token-123');
    });

    it('executes multiple interceptors in the order they were registered', function () {
        $history = [];

        Http::request()
            ->interceptRequest(function ($r) use (&$history) {
                $history[] = 'first';

                return $r;
            })
            ->interceptRequest(function ($r) use (&$history) {
                $history[] = 'second';

                return $r;
            })
            ->get(HttpBin::url('/get'))
            ->wait()
        ;

        expect($history)->toBe(['first', 'second']);
    });

    it('can short-circuit the connection using full pipeline intercept', function () {
        $response = Http::request()
            ->intercept(function (RequestInterface $request, callable $next) {
                return new Response('Blocked by interceptor', 403);
            })
            ->get(HttpBin::url('/get'))
            ->wait()
        ;

        expect($response->status())->toBe(403)
            ->and($response->body())->toBe('Blocked by interceptor')
        ;
    });

    it('can perform actions before and after the request using full pipeline intercept', function () {
        $log = [];

        $response = Http::request()
            ->intercept(function (RequestInterface $request, callable $next) use (&$log) {
                $log[] = 'before';

                /** @var Hibla\Promise\Interfaces\PromiseInterface $promise */
                $promise = $next($request);

                return $promise->then(function (ResponseInterface $response) use (&$log) {
                    $log[] = 'after';

                    return $response->withHeader('X-Flow', 'captured');
                });
            })
            ->get(HttpBin::url('/get'))
            ->wait()
        ;

        expect($log)->toBe(['before', 'after'])
            ->and($response->header('X-Flow'))->toBe('captured')
        ;
    });

    it('allows using await() directly inside interceptors', function () {
        $response = Http::request()
            ->intercept(function (RequestInterface $request, callable $next) {
                await(delay(0.02));
                $request = $request->withHeader('X-Await-Checked', 'yes');

                return $next($request);
            })
            ->get(HttpBin::url('/headers'))
            ->wait()
        ;

        expect($response->json('headers.X-Await-Checked.0'))->toBe('yes');
    });

    it('preserves immutability of the client when adding interceptors', function () {
        $client = Http::request()->withHeader('X-Base', 'true');

        $interceptedClient = $client->interceptRequest(fn ($r) => $r->withHeader('X-Intercept', 'true'));

        $res1 = $client->get(HttpBin::url('/headers'))->wait();
        expect($res1->json('headers.X-Intercept'))->toBeNull();
        expect($res1->json('headers.X-Base.0'))->toBe('true');

        $res2 = $interceptedClient->get(HttpBin::url('/headers'))->wait();
        expect($res2->json('headers.X-Intercept.0'))->toBe('true');
        expect($res2->json('headers.X-Base.0'))->toBe('true');
    });

    it('handles exceptions thrown inside interceptors correctly', function () {
        $promise = Http::request()
            ->interceptRequest(function ($r) {
                throw new RuntimeException('Interceptor failure');
            })
            ->get(HttpBin::url('/get'))
        ;

        expect(fn () => $promise->wait())->toThrow(RuntimeException::class, 'Interceptor failure');
    });

    it('can wrap response body using a response interceptor', function () {
        $response = Http::request()
            ->interceptResponse(function (ResponseInterface $res) {
                $body = $res->body();

                return new Response('Wrapped: ' . $body, $res->getStatusCode(), $res->getHeaders());
            })
            ->get(HttpBin::url('/ip'))
            ->wait()
        ;

        expect($response->body())->toStartWith('Wrapped: {');
    });
});
