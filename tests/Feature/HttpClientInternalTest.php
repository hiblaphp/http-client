<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\SSE\SSEEvent;

beforeEach(function () {
    Http::startTesting();
});

afterEach(function () {
    Http::stopTesting();
});

describe('Basic Requests', function () {
    test('it can perform a basic GET request', function () {
        Http::mock()
            ->url('https://api.example.com/posts/1')
            ->respondJson(['id' => 1, 'title' => 'Test Post'])
            ->register()
        ;

        $response = Http::get('https://api.example.com/posts/1')->wait();

        expect($response)->toBeInstanceOf(Response::class)
            ->and($response->successful())->toBeTrue()
            ->and($response->json())->toBe(['id' => 1, 'title' => 'Test Post'])
        ;

        Http::assertRequestMade('GET', 'https://api.example.com/posts/1');
        Http::assertRequestCount(1);
    });

    test('it can perform a basic POST request with a JSON body', function () {
        Http::mock()
            ->url('https://api.example.com/posts')
            ->respondJson(['id' => 2])
            ->status(201)
            ->register()
        ;

        $response = Http::post('https://api.example.com/posts', ['title' => 'New Post'])->wait();

        expect($response->status())->toBe(201)
            ->and($response->json())->toBe(['id' => 2])
        ;

        Http::assertRequestMade('POST', 'https://api.example.com/posts');
        Http::assertRequestWithJson('POST', 'https://api.example.com/posts', ['title' => 'New Post']);
    });
});

describe('Headers', function () {
    test('it can send custom headers', function () {
        Http::mock()->url('*')->respondWith('OK')->register();

        Http::withHeaders([
            'X-Custom-Header' => 'MyValue',
            'X-Another' => 'AnotherValue',
        ])->get('/')->wait();

        Http::assertHeaderSent('X-Custom-Header', 'MyValue');
        Http::assertHeaderSent('X-Another', 'AnotherValue');
        expect(true)->toBeTrue();
    });

    test('it correctly adds a bearer token', function () {
        Http::mock()->url('/secure')->respondWith('Success')->register();

        Http::withToken('my-secret-token')->get('/secure')->wait();

        Http::assertBearerTokenSent('my-secret-token');
        expect(true)->toBeTrue();
    });

    test('it sets the Accept header correctly', function () {
        Http::mock()->url('*')->respondWith('OK')->register();

        Http::accept('application/json')->get('/')->wait();

        Http::assertAcceptHeader('application/json');
        expect(true)->toBeTrue();
    });
});

describe('Request Body', function () {
    test('it sends a JSON request body correctly', function () {
        Http::mock()->url('/json')->respondWith('OK')->register();

        Http::withJson(['foo' => 'bar'])->post('/json')->wait();

        Http::assertContentType('application/json');
        Http::assertRequestWithJson('POST', '/json', ['foo' => 'bar']);
        expect(true)->toBeTrue();
    });

    test('it sends a url-encoded form body correctly', function () {
        Http::mock()->url('/form')->respondWith('OK')->register();

        Http::withForm(['foo' => 'bar', 'baz' => 'qux'])->post('/form')->wait();

        Http::assertContentType('application/x-www-form-urlencoded');
        Http::assertRequestWithBody('POST', '/form', 'foo=bar&baz=qux');
        expect(true)->toBeTrue();
    });
});

describe('Retries', function () {
    test('it retries a failed request the specified number of times', function () {
        Http::mock()
            ->url('/retry-test')
            ->failUntilAttempt(3)
            ->respondWith('Finally succeeded on attempt 3')
            ->register()
        ;

        $response = Http::retry(3)->get('/retry-test')->wait();

        expect($response->successful())->toBeTrue()
            ->and($response->body())->toBe('Finally succeeded on attempt 3')
        ;

        Http::assertRequestCount(3);
    });
});

describe('Server-Sent Events (SSE)', function () {
    test('it can mock and receive SSE events', function () {
        Http::mock()
            ->url('https://api.example.com/stream')
            ->respondWithSSE([
                ['event' => 'greeting', 'data' => 'hello'],
                ['id' => '123', 'data' => 'world'],
            ])
            ->register()
        ;

        $receivedEvents = [];

        Http::sse('https://api.example.com/stream')
            ->onEvent(function (SSEEvent $event) use (&$receivedEvents) {
                $receivedEvents[] = $event;
            })
            ->connect()
            ->wait()
        ;

        Http::assertSSEConnectionMade('https://api.example.com/stream');
        expect($receivedEvents)->toHaveCount(2);
        expect($receivedEvents[0]->event)->toBe('greeting');
        expect($receivedEvents[0]->data)->toBe('hello');
        expect($receivedEvents[1]->id)->toBe('123');
        expect($receivedEvents[1]->data)->toBe('world');
    });
});

describe('Error Handling', function () {
    test('it correctly identifies a 404 client error', function () {
        Http::mock()
            ->url('/not-found')
            ->status(404)
            ->respondWith('Not Found')
            ->register()
        ;

        $response = Http::get('/not-found')->wait();

        expect($response->successful())->toBeFalse();
        expect($response->clientError())->toBeTrue();
        expect($response->serverError())->toBeFalse();
        expect($response->status())->toBe(404);
    });

    test('it correctly identifies a 500 server error', function () {
        Http::mock()
            ->url('/server-error')
            ->status(500)
            ->respondWith('Server Error')
            ->register()
        ;

        $response = Http::get('/server-error')->wait();

        expect($response->successful())->toBeFalse();
        expect($response->clientError())->toBeFalse();
        expect($response->serverError())->toBeTrue();
        expect($response->status())->toBe(500);
    });
});
