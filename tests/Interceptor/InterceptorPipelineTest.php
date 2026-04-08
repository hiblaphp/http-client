<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Http;
use Hibla\HttpClient\Interfaces\RequestInterface;
use Hibla\HttpClient\Interfaces\ResponseInterface;
use Hibla\HttpClient\Response;
use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Tests\Fixtures\AddAuthHeaderInterceptor;
use Tests\Fixtures\HttpBin;
use Tests\Fixtures\PipelineLoggerInterceptor;
use Tests\Fixtures\ResponseTaggingService;

use function Hibla\await;
use function Hibla\delay;

beforeEach(function () {
    HttpBin::skipIfUnreachable();
});

describe('Interceptor Pipeline', function () {

    it('can modify request headers via interceptRequest', function () {
        $response = Http::client()
            ->interceptRequest(fn (RequestInterface $r) => $r->withHeader('X-Custom-Req', 'hibla-power'))
            ->get(HttpBin::url('/headers'))
            ->wait()
        ;

        expect($response->json('headers.X-Custom-Req.0'))->toBe('hibla-power');
    });

    it('can modify response via interceptResponse', function () {
        $response = Http::client()
            ->interceptResponse(fn (ResponseInterface $res) => $res->withHeader('X-Intercepted-By', 'unit-test'))
            ->get(HttpBin::url('/get'))
            ->wait()
        ;

        expect($response->header('X-Intercepted-By'))->toBe('unit-test');
    });

    it('supports asynchronous request interceptors (e.g. Token Refresh)', function () {
        $response = Http::client()
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

        Http::client()
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
        $response = Http::client()
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

        $response = Http::client()
            ->intercept(function (RequestInterface $request, callable $next) use (&$log) {
                $log[] = 'before';

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
        $response = Http::client()
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
        $client = Http::client()->withHeader('X-Base', 'true');

        $interceptedClient = $client->interceptRequest(fn ($r) => $r->withHeader('X-Intercept', 'true'));

        $res1 = $client->get(HttpBin::url('/headers'))->wait();
        expect($res1->json('headers.X-Intercept'))->toBeNull();
        expect($res1->json('headers.X-Base.0'))->toBe('true');

        $res2 = $interceptedClient->get(HttpBin::url('/headers'))->wait();
        expect($res2->json('headers.X-Intercept.0'))->toBe('true');
        expect($res2->json('headers.X-Base.0'))->toBe('true');
    });

    it('handles exceptions thrown inside interceptors correctly', function () {
        $promise = Http::client()
            ->interceptRequest(function ($r) {
                throw new RuntimeException('Interceptor failure');
            })
            ->get(HttpBin::url('/get'))
        ;

        expect(fn () => $promise->wait())->toThrow(RuntimeException::class, 'Interceptor failure');
    });

    it('can wrap response body using a response interceptor', function () {
        $response = Http::client()
            ->interceptResponse(function (ResponseInterface $res) {
                $body = $res->body();

                return new Response('Wrapped: ' . $body, $res->getStatusCode(), $res->getHeaders());
            })
            ->get(HttpBin::url('/ip'))
            ->wait()
        ;

        expect($response->body())->toStartWith('Wrapped: {');
    });

    it('supports invokable classes as request interceptors', function () {
        $response = Http::client()
            ->interceptRequest(new AddAuthHeaderInterceptor())
            ->get(HttpBin::url('/headers'))
            ->wait()
        ;

        expect($response->json('headers.X-Invokable-Auth.0'))->toBe('active');
    });

    it('supports invokable classes as full pipeline interceptors', function () {
        $logger = new PipelineLoggerInterceptor();

        Http::client()
            ->intercept($logger)
            ->get(HttpBin::url('/get'))
            ->wait()
        ;

        expect($logger->history)->toBe(['invokable-before', 'invokable-after']);
    });

    it('supports object method references as response interceptors', function () {
        $service = new ResponseTaggingService();

        $response = Http::client()
            ->interceptResponse([$service, 'tagResponse'])
            ->get(HttpBin::url('/get'))
            ->wait()
        ;

        expect($response->header('X-Service-Tagged'))->toBe('true');
    });

    it('supports complex async work inside an invokable pipeline interceptor using await', function () {
        $asyncInterceptor = new class () {
            public function __invoke(RequestInterface $request, callable $next): PromiseInterface
            {
                await(delay(0.01));
                $request = $request->withHeader('X-Fiber-Invokable', 'works');

                return $next($request);
            }
        };

        $response = Http::client()
            ->intercept($asyncInterceptor)
            ->get(HttpBin::url('/headers'))
            ->wait()
        ;

        expect($response->json('headers.X-Fiber-Invokable.0'))->toBe('works');
    });

    it('stops the pipeline and does not send the request if cancelled during interception', function () {
        Http::startTesting()->enablePassthrough();

        $url = HttpBin::url('/get');
        $requestStarted = false;

        $promise = Http::client()
            ->intercept(function (RequestInterface $request, callable $next) use (&$requestStarted) {
                await(delay(0.5));
                $requestStarted = true;

                return $next($request);
            })
            ->get($url)
        ;

        Loop::addTimer(0.1, fn () => $promise->cancel());

        try {
            await($promise);
        } catch (CancelledException $e) {
            // Expected
        }

        expect($requestStarted)->toBeFalse();

        Http::assertNoRequestsMade();
        Http::stopTesting();
    });
});
