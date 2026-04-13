<?php

declare(strict_types=1);

use Hibla\HttpClient\Handlers\InterceptorHandler;
use Hibla\HttpClient\Interfaces\RequestInterface;
use Hibla\HttpClient\Request;
use Hibla\HttpClient\Response;
use Hibla\Promise\Promise;

use function Hibla\await;

describe('InterceptorHandler', function () {
    test('executes directly when no interceptors are provided', function () {
        $handler = new InterceptorHandler();
        $request = new Request();

        $executor = fn (RequestInterface $req) => Promise::resolved(new Response('direct response'));

        $promise = $handler->process($request, [], $executor);
        $response = await($promise);

        expect($response)->toBeInstanceOf(Response::class)
            ->and($response->body())->toBe('direct response')
        ;
    });

    test('interceptor can modify request before reaching executor', function () {
        $handler = new InterceptorHandler();
        $request = new Request();

        $executor = function (RequestInterface $req) {
            $val = $req->getHeaderLine('X-Test-Header');

            return Promise::resolved(new Response("Header was: {$val}"));
        };

        $interceptor = function (RequestInterface $req, callable $next) {
            $modifiedReq = $req->withHeader('X-Test-Header', 'InjectedValue');

            return $next($modifiedReq);
        };

        $promise = $handler->process($request, [$interceptor], $executor);
        $response = await($promise);

        expect($response->body())->toBe('Header was: InjectedValue');
    });

    test('interceptor can modify response after executor finishes', function () {
        $handler = new InterceptorHandler();
        $request = new Request();

        $executor = fn (RequestInterface $req) => Promise::resolved(new Response('original body'));

        $interceptor = function (RequestInterface $req, callable $next) {
            return $next($req)->then(function (Response $res) {
                return new Response($res->body() . ' + intercepted');
            });
        };

        $promise = $handler->process($request, [$interceptor], $executor);
        $response = await($promise);

        expect($response->body())->toBe('original body + intercepted');
    });

    test('interceptors execute in the correct onion order (first in, first to process request, last to process response)', function () {
        $handler = new InterceptorHandler();
        $request = new Request();
        $executionOrder = [];

        $executor = function (RequestInterface $req) use (&$executionOrder) {
            $executionOrder[] = 'executor';

            return Promise::resolved(new Response('ok'));
        };

        $interceptorA = function (RequestInterface $req, callable $next) use (&$executionOrder) {
            $executionOrder[] = 'A_req';

            return $next($req)->then(function ($res) use (&$executionOrder) {
                $executionOrder[] = 'A_res';

                return $res;
            });
        };

        $interceptorB = function (RequestInterface $req, callable $next) use (&$executionOrder) {
            $executionOrder[] = 'B_req';

            return $next($req)->then(function ($res) use (&$executionOrder) {
                $executionOrder[] = 'B_res';

                return $res;
            });
        };

        $promise = $handler->process($request, [$interceptorA, $interceptorB], $executor);
        await($promise);

        expect($executionOrder)->toBe([
            'A_req',
            'B_req',
            'executor',
            'B_res',
            'A_res',
        ]);
    });

    test('interceptor can short-circuit the pipeline by not calling next', function () {
        $handler = new InterceptorHandler();
        $request = new Request();
        $executorCalled = false;

        $executor = function (RequestInterface $req) use (&$executorCalled) {
            $executorCalled = true;

            return Promise::resolved(new Response('should not be seen'));
        };

        $interceptor = function (RequestInterface $req, callable $next) {
            return Promise::resolved(new Response('short-circuited!'));
        };

        $promise = $handler->process($request, [$interceptor], $executor);
        $response = await($promise);

        expect($response->body())->toBe('short-circuited!')
            ->and($executorCalled)->toBeFalse()
        ;
    });

    test('throws LogicException if interceptor returns null', function () {
        $handler = new InterceptorHandler();
        $request = new Request();

        $executor = fn (RequestInterface $req) => Promise::resolved(new Response('ok'));

        $interceptor = function (RequestInterface $req, callable $next) {
            $next($req);

            return null;
        };

        $promise = $handler->process($request, [$interceptor], $executor);

        expect(fn () => await($promise))
            ->toThrow(LogicException::class, 'must return a Hibla\Promise\Interfaces\PromiseInterface or Hibla\HttpClient\Response, got null/void')
        ;
    });

    test('throws LogicException if interceptor returns a scalar type instead of Promise/Response', function () {
        $handler = new InterceptorHandler();
        $request = new Request();

        $executor = fn (RequestInterface $req) => Promise::resolved(new Response('ok'));

        $interceptor = function (RequestInterface $req, callable $next) {
            return 'This is a string, not a promise';
        };

        $promise = $handler->process($request, [$interceptor], $executor);

        expect(fn () => await($promise))
            ->toThrow(LogicException::class, 'must return a Hibla\Promise\Interfaces\PromiseInterface or Hibla\HttpClient\Response, got string')
        ;
    });

    test('supports returning raw Response object instead of Promise from interceptor', function () {
        $handler = new InterceptorHandler();
        $request = new Request();

        $executor = fn (RequestInterface $req) => Promise::resolved(new Response('ok'));

        $interceptor = function (RequestInterface $req, callable $next) {
            return new Response('returned directly without promise wrapper');
        };

        $promise = $handler->process($request, [$interceptor], $executor);
        $response = await($promise);

        expect($response)->toBeInstanceOf(Response::class)
            ->and($response->body())->toBe('returned directly without promise wrapper')
        ;
    });

    test('throws LogicException if final result is not a Response when requireResponse is true', function () {
        $handler = new InterceptorHandler();
        $request = new Request();

        $executor = fn (RequestInterface $req) => Promise::resolved(['status' => 'ok']);

        $interceptor = function (RequestInterface $req, callable $next) {
            return $next($req);
        };

        $promise = $handler->process($request, [$interceptor], $executor, requireResponse: true);

        expect(fn () => await($promise))
            ->toThrow(LogicException::class, 'must resolve to a Hibla\HttpClient\Response instance, got array')
        ;
    });

    test('allows returning an array when requireResponse is false (e.g. downloads/uploads)', function () {
        $handler = new InterceptorHandler();
        $request = new Request();

        $executor = fn (RequestInterface $req) => Promise::resolved([
            'file' => '/tmp/download.txt',
            'status' => 200,
        ]);

        $interceptor = function (RequestInterface $req, callable $next) {
            return $next($req)->then(function (array $result) {
                $result['intercepted'] = true;

                return $result;
            });
        };

        $promise = $handler->process($request, [$interceptor], $executor, requireResponse: false);
        $result = await($promise);

        expect($result)->toBeArray()
            ->and($result['file'])->toBe('/tmp/download.txt')
            ->and($result['intercepted'])->toBeTrue()
        ;
    });
});
