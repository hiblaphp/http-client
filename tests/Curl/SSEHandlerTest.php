<?php

declare(strict_types=1);

namespace Tests\Handlers\Curl;

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\CookieJar;
use Hibla\HttpClient\Exceptions\HttpStreamException;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Handlers\Curl\SSEHandler;
use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\SSE\SSEResponse;
use Tests\Fixtures\HttpBin;

beforeEach(function () {
    HttpBin::skipIfUnreachable();
    Loop::reset();
});

afterEach(function () {
    Loop::reset();
});

describe('SSEHandler', function () {

    describe('Basic Connection', function () {

        it('resolves with an SSEResponse on a successful handshake', function () {
            $handler = new SSEHandler();
            $promise = $handler->connect(HttpBin::url('/get'));

            $response = null;
            $promise->then(function ($res) use (&$response) {
                $response = $res;
                Loop::stop();
            });

            Loop::run();

            expect($response)->toBeInstanceOf(SSEResponse::class);
            expect($response->status())->toBe(200);
        });

        it('rejects with HttpStreamException on non-2xx status codes', function () {
            $handler = new SSEHandler();
            $promise = $handler->connect(HttpBin::url('/status/404'));

            $error = null;
            $promise->catch(function ($err) use (&$error) {
                $error = $err;
                Loop::stop();
            });

            Loop::run();

            expect($error)->toBeInstanceOf(HttpStreamException::class);
            expect($error->getMessage())->toContain('status: 404');
        });

        it('persists handshake cookies into the provided jar', function () {
            $handler = new SSEHandler();
            $jar = new CookieJar();
            $url = HttpBin::url('/response-headers?Set-Cookie=sse_test%3Dhandshake%3B+Path%3D%2F');

            $promise = $handler->connect($url, ['_cookie_jar' => $jar]);

            $promise->then(fn() => Loop::stop());
            Loop::run();

            $allCookies = $jar->getAllCookies();
            $found = false;
            foreach ($allCookies as $cookie) {
                if ($cookie->getName() === 'sse_test' && $cookie->getValue() === 'handshake') {
                    $found = true;
                    break;
                }
            }

            expect($found)->toBeTrue();
        });
    });

    describe('Event Handling', function () {

        it('calls onEvent when data is received', function () {
            $handler = new SSEHandler();
            $eventReceived = null;
            $base64Sse = 'aWQ6IDEKZXZlbnQ6IG1lc3NhZ2UKZGF0YTogaGVsbG8gd29ybGQKCg==';

            $promise = $handler->connect(
                HttpBin::url("/base64/{$base64Sse}"),
                [],
                function (SSEEvent $event) use (&$eventReceived) {
                    $eventReceived = $event;
                    Loop::stop();
                }
            );

            Loop::run();

            expect($eventReceived)->toBeInstanceOf(SSEEvent::class);
            expect($eventReceived->id)->toBe('1');
            expect($eventReceived->event)->toBe('message');
            expect($eventReceived->data)->toBe('hello world');
        });
    });

    describe('Reconnection Logic', function () {

        it('attempts to reconnect on network failure when enabled', function () {
            $handler = new SSEHandler();
            $reconnectCount = 0;

            $config = new SSEReconnectConfig(
                enabled: true,
                maxAttempts: 2,
                initialDelay: 0.01,
                jitter: false,
                onReconnect: function () use (&$reconnectCount) {
                    $reconnectCount++;
                }
            );

            $promise = $handler->connect(
                'http://127.0.0.1:19999',
                [CURLOPT_CONNECTTIMEOUT => 1],
                null,
                null,
                $config
            );

            $error = null;
            $promise->catch(function ($err) use (&$error) {
                $error = $err;
                Loop::stop();
            });

            Loop::run();

            expect($reconnectCount)->toBeGreaterThan(0);
            expect($error)->toBeInstanceOf(NetworkException::class);
        });

        it('updates Last-Event-ID in the state when an event with ID is received', function () {
            $handler = new SSEHandler();
            $lastIdSeen = null;
            $config = new SSEReconnectConfig(enabled: true);
            $base64Sse = 'aWQ6IDk5CmRhdGE6IHVwZGF0ZSBpZAoK';

            $promise = $handler->connect(
                HttpBin::url("/base64/{$base64Sse}"),
                [],
                function (SSEEvent $event) use (&$lastIdSeen) {
                    $lastIdSeen = $event->id;
                    Loop::stop();
                },
                null,
                $config
            );

            Loop::run();
            expect($lastIdSeen)->toBe('99');
        });
    });

    describe('Cancellation', function () {

        it('cancels the request and closes response on promise cancellation', function () {
            $handler = new SSEHandler();
            $promise = $handler->connect(HttpBin::url('/delay/2'));

            $response = null;
            $promise->then(function ($res) use (&$response) {
                $response = $res;
            });

            Loop::addTimer(0.1, function () use ($promise) {
                $promise->cancel();
                Loop::stop();
            });

            Loop::run();

            expect($promise->isCancelled())->toBeTrue();
            expect($response)->toBeNull();
        });
    });
});