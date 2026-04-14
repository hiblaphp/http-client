<?php

declare(strict_types=1);

use Hibla\HttpClient\CookieJar;
use Hibla\HttpClient\Exceptions\TimeoutException;
use Hibla\HttpClient\Interfaces\RequestInterface;
use Hibla\HttpClient\Interfaces\ResponseInterface;
use Hibla\HttpClient\Response;
use Hibla\Promise\Promise;
use Tests\Fixtures\HttpBin;

use function Hibla\await;
use function Hibla\HttpClient\fetch;

function extractHttpBinValue(mixed $value): mixed
{
    return is_array($value) ? ($value[0] ?? null) : $value;
}

beforeAll(function () {
    HttpBin::skipIfUnreachable();
});

describe('CurlFetchRequest Lifecycle Integration (HttpBin)', function () {
    test('it correctly maps complex JSON payloads and HTTP methods', function () {
        $payload = ['id' => 123, 'meta' => ['foo' => 'bar']];

        $response = await(fetch(HttpBin::url('/put'), [
            'method' => 'PUT',
            'json' => $payload,
            'headers' => ['X-Client-Id' => 'Hibla-Fetch'],
        ]));

        expect($response->status())->toBe(200)
            ->and($response->json('json'))->toBe($payload)
            ->and(extractHttpBinValue($response->json('headers.X-Client-Id')))->toBe('Hibla-Fetch')
        ;
    });

    test('it handles multipart form data with files in the options array', function () {
        $response = await(fetch(HttpBin::url('/post'), [
            'method' => 'POST',
            'multipart' => [
                'field1' => 'value1',
                'file_upload' => [
                    'contents' => 'fake file content',
                    'filename' => 'test.txt',
                    'Content-Type' => 'text/plain',
                ],
            ],
        ]));

        expect($response->status())->toBe(200)
            ->and(extractHttpBinValue($response->json('form.field1')))->toBe('value1')
            ->and(extractHttpBinValue($response->json('files.file_upload')))->toBe('fake file content')
        ;
    });

    test('it handles various authentication schemes in a single options tree', function (array $auth, string $path, string $checkKey) {
        $response = await(fetch(HttpBin::url($path), [
            'auth' => $auth,
        ]));

        expect($response->status())->toBe(200)
            ->and($response->json($checkKey))->toBe(true)
        ;
    })->with([
        'bearer' => [['bearer' => 'token123'], '/bearer', 'authenticated'],
        'basic' => [['basic' => ['username' => 'u', 'password' => 'p']], '/basic-auth/u/p', 'authenticated'],
    ]);

    test('lifecycle: intercept_request can modify the outgoing request asynchronously', function () {
        $response = await(fetch(HttpBin::url('/headers'), [
            'intercept_request' => function (RequestInterface $request) {
                $promise = new Promise();
                $promise->resolve($request->withHeader('X-Async-Token', 'Resolved-123'));

                return $promise;
            },
        ]));

        expect(extractHttpBinValue($response->json('headers.X-Async-Token')))->toBe('Resolved-123');
    });

    test('lifecycle: intercept_response can process the response body before resolving', function () {
        $response = await(fetch(HttpBin::url('/get'), [
            'intercept_response' => function (ResponseInterface $response) {
                $body = $response->json();
                $body['injected_by_interceptor'] = true;

                return new Response(json_encode($body), $response->status(), $response->getHeaders());
            },
        ]));

        expect($response->json('injected_by_interceptor'))->toBeTrue();
    });

    test('lifecycle: full pipeline intercept can short-circuit and bypass network', function () {
        $response = await(fetch(HttpBin::url('/get'), [
            'intercept' => function (RequestInterface $request, callable $next) {
                return new Response('{"mock": true}', 201, ['Content-Type' => 'application/json']);
            },
        ]));

        expect($response->status())->toBe(201)
            ->and($response->json('mock'))->toBeTrue()
        ;
    });

    test('it integrates with shared CookieJars for session persistence', function () {
        $jar = new CookieJar();
        $url = HttpBin::url('/cookies/set?session_id=abc123');

        await(fetch($url, [
            'cookie_jar' => $jar,
            'follow_redirects' => true,
        ]));

        $response = await(fetch(HttpBin::url('/cookies'), ['cookie_jar' => $jar]));

        expect($response->json('cookies.session_id'))->toBe('abc123');
    });

    test('it respects redirect limits and protocol versions', function () {
        $response = await(fetch(HttpBin::url('/redirect/3'), [
            'follow_redirects' => true,
            'max_redirects' => 5,
            'http_version' => '1.1',
        ]));

        expect($response->status())->toBe(200)
            ->and($response->getProtocolVersion())->toBe('1.1')
        ;
    });

    test('it throws proper TimeoutException for transport-level failures', function () {
        $delayUrl = HttpBin::url('/delay/3');

        expect(fn () => await(fetch($delayUrl, ['timeout' => 1])))
            ->toThrow(TimeoutException::class)
        ;
    });

    test('it passes through raw cURL options via integer keys', function () {
        $response = await(fetch(HttpBin::url('/headers'), [
            CURLOPT_USERAGENT => 'Agent-X',
            CURLOPT_REFERER => 'https://hibla.dev',
        ]));

        $headers = $response->json('headers');

        expect(extractHttpBinValue($headers['User-Agent']))->toBe('Agent-X')
            ->and(rtrim(extractHttpBinValue($headers['Referer'] ?? ''), '/'))->toBe('https://hibla.dev')
        ;
    });
});
