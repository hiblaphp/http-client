<?php

use Hibla\HttpClient\Cookie;
use Hibla\HttpClient\Interfaces\CookieJarInterface;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\Stream;

afterEach(function () {
    Mockery::close();
});

describe('Response Construction & Basic Getters', function () {
    it('constructs correctly with a string body, status, and headers', function () {
        $response = new Response('Hello World', 201, ['X-Test' => 'Value']);

        expect($response->getStatusCode())->toBe(201);
        expect($response->getReasonPhrase())->toBe('Created');
        expect($response->body())->toBe('Hello World');
        expect($response->getHeaderLine('X-Test'))->toBe('Value');
    });

    it('constructs correctly with a Stream body', function () {
        $stream = Stream::fromString('Stream Content');
        $response = new Response($stream, 200);

        expect($response->getBody())->toBe($stream);
        expect($response->body())->toBe('Stream Content');
    });

    it('handles unknown status codes gracefully', function () {
        $response = new Response('', 599);
        expect($response->getReasonPhrase())->toBe('Unknown Status Code');
    });
});

describe('Immutability', function () {
    it('withStatus returns a new instance', function () {
        $r1 = new Response('', 200);
        $r2 = $r1->withStatus(404);

        expect($r1)->not->toBe($r2);
        expect($r1->getStatusCode())->toBe(200);
        expect($r2->getStatusCode())->toBe(404);
        expect($r2->getReasonPhrase())->toBe('Not Found');
    });

    it('withStatus throws an exception for invalid status codes', function () {
        $response = new Response();
        expect(fn () => $response->withStatus(99))->toThrow(InvalidArgumentException::class);
        expect(fn () => $response->withStatus(600))->toThrow(InvalidArgumentException::class);
    });
});

describe('Body Helpers', function () {
    it('json() correctly decodes a valid JSON body', function () {
        $response = new Response('{"user": {"id": 1, "name": "John"}}');
        expect($response->json())->toBe(['user' => ['id' => 1, 'name' => 'John']]);
    });

    it('json() returns an empty array for invalid JSON', function () {
        $response = new Response('this is not json');
        expect($response->json())->toBe([]);
    });

    it('json() returns an empty array for an empty body', function () {
        $response = new Response('');
        expect($response->json())->toBe([]);
    });
});

describe('Header Helpers', function () {
    $response = new Response('', 200, [
        'Content-Type' => 'application/json',
        'X-Request-ID' => 'abc-123',
    ]);

    it('headers() returns a lowercase-keyed map of headers', function () use ($response) {
        $headers = $response->headers();
        expect($headers)->toHaveKeys(['content-type', 'x-request-id']);
        expect($headers['content-type'])->toBe('application/json');
    });

    it('header() is case-insensitive', function () use ($response) {
        expect($response->header('content-type'))->toBe('application/json');
        expect($response->header('Content-Type'))->toBe('application/json');
        expect($response->header('CONTENT-TYPE'))->toBe('application/json');
    });

    it('header() returns null for a non-existent header', function () use ($response) {
        expect($response->header('X-Nonexistent'))->toBeNull();
    });
});

describe('Status Helpers', function () {
    dataset('ok_statuses', [[200], [201], [204], [299]]);
    dataset('client_error_statuses', [[400], [404], [422], [499]]);
    dataset('server_error_statuses', [[500], [503], [599]]);
    dataset('other_statuses', [[100], [302]]);

    it('ok() and successful() are true for 2xx statuses', function ($status) {
        $response = new Response('', $status);
        expect($response->successful())->toBeTrue();
        expect($response->successful())->toBeTrue();
    })->with('ok_statuses');

    it('ok() and successful() are false for non-2xx statuses', function ($status) {
        $response = new Response('', $status);
        expect($response->successful())->toBeFalse();
        expect($response->successful())->toBeFalse();
    })->with('client_error_statuses', 'server_error_statuses', 'other_statuses');

    it('clientError() is true for 4xx statuses', function ($status) {
        $response = new Response('', $status);
        expect($response->clientError())->toBeTrue();
    })->with('client_error_statuses');

    it('clientError() is false for non-4xx statuses', function ($status) {
        $response = new Response('', $status);
        expect($response->clientError())->toBeFalse();
    })->with('ok_statuses', 'server_error_statuses', 'other_statuses');

    it('serverError() is true for 5xx statuses', function ($status) {
        $response = new Response('', $status);
        expect($response->serverError())->toBeTrue();
    })->with('server_error_statuses');

    it('serverError() is false for non-5xx statuses', function ($status) {
        $response = new Response('', $status);
        expect($response->serverError())->toBeFalse();
    })->with('ok_statuses', 'client_error_statuses', 'other_statuses');
});

describe('Cookie Helpers', function () {
    it('getCookies() parses a single Set-Cookie header', function () {
        $response = new Response('', 200, ['Set-Cookie' => 'user=john; path=/']);
        $cookies = $response->getCookies();
        expect($cookies)->toHaveCount(1);
        expect($cookies[0])->toBeInstanceOf(Cookie::class);
        expect($cookies[0]->getName())->toBe('user');
    });

    it('getCookies() parses multiple Set-Cookie headers', function () {
        $response = new Response('', 200, [
            'Set-Cookie' => [
                'user=john; path=/',
                'session=abc; path=/; secure',
            ],
        ]);
        $cookies = $response->getCookies();
        expect($cookies)->toHaveCount(2);
        expect($cookies[1]->getName())->toBe('session');
    });

    it('applyCookiesToJar() calls setCookie on the jar for each cookie', function () {
        $cookieJarMock = Mockery::mock(CookieJarInterface::class);

        $cookieJarMock->shouldReceive('setCookie')->twice()->with(Mockery::type(Cookie::class));

        $response = new Response('', 200, [
            'Set-Cookie' => [
                'user=john; path=/',
                'session=abc; path=/; secure',
            ],
        ]);

        $response->applyCookiesToJar($cookieJarMock);

        expect(true)->toBeTrue();
    });
});
