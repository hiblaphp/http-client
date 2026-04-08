<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Hibla\HttpClient\HttpClient;
use Tests\Fixtures\HttpBin;

describe('HttpBin Integration Tests', function () {

    beforeEach(function () {
        HttpBin::skipIfUnreachable();
    });

    test('fetches a GET response', function () {
        $response = Http::get(HttpBin::url('/get'))->wait();

        expect($response->status())->toBe(200)
            ->and($response->successful())->toBeTrue()
            ->and($response->json())->toHaveKey('url')
            ->and($response->json())->toHaveKey('headers')
        ;
    });

    test('sends a POST request with JSON body', function () {
        $payload = [
            'title' => 'Integration Test Post',
            'body' => 'This is a test post from our integration tests',
            'userId' => 1,
        ];

        $response = Http::withJson($payload)
            ->post(HttpBin::url('/post'))
            ->wait()
        ;

        expect($response->status())->toBe(200)
            ->and($response->json('json.title'))->toBe('Integration Test Post')
            ->and($response->json('json.body'))->toBe('This is a test post from our integration tests')
            ->and($response->json('json.userId'))->toBe(1)
        ;
    });

    test('sends a PUT request with JSON body', function () {
        $payload = [
            'title' => 'Updated Title',
            'body' => 'Updated body content',
        ];

        $response = Http::withJson($payload)
            ->put(HttpBin::url('/put'))
            ->wait()
        ;

        expect($response->status())->toBe(200)
            ->and($response->json('json.title'))->toBe('Updated Title')
            ->and($response->json('json.body'))->toBe('Updated body content')
        ;
    });

    test('sends a PATCH request with JSON body', function () {
        $response = Http::withJson(['title' => 'Patched Title'])
            ->patch(HttpBin::url('/patch'))
            ->wait()
        ;

        expect($response->status())->toBe(200)
            ->and($response->json('json.title'))->toBe('Patched Title')
        ;
    });

    test('sends a DELETE request', function () {
        $response = Http::delete(HttpBin::url('/delete'))->wait();

        expect($response->status())->toBe(200)
            ->and($response->successful())->toBeTrue()
        ;
    });

    test('sends query parameters and receives them back', function () {
        $response = Http::get(HttpBin::url('/get'), ['userId' => 1, 'filter' => 'active'])->wait();

        expect($response->status())->toBe(200)
            ->and($response->json('args.userId.0'))->toBe('1')
            ->and($response->json('args.filter.0'))->toBe('active')
        ;
    });

    test('handles 404 not found', function () {
        $response = Http::get(HttpBin::url('/status/404'))->wait();

        expect($response->status())->toBe(404)
            ->and($response->failed())->toBeTrue()
            ->and($response->clientError())->toBeTrue()
            ->and($response->successful())->toBeFalse()
        ;
    });

    test('handles 500 server error', function () {
        $response = Http::get(HttpBin::url('/status/500'))->wait();

        expect($response->status())->toBe(500)
            ->and($response->failed())->toBeTrue()
            ->and($response->serverError())->toBeTrue()
            ->and($response->successful())->toBeFalse()
        ;
    });

    test('extracts nested JSON data using dot notation', function () {
        $response = Http::get(HttpBin::url('/get'))->wait();

        expect($response->json('headers.Host.0'))->toBeString()
            ->and($response->json('headers.User-Agent.0'))->toBeString()
        ;
    });

    test('handles response headers correctly', function () {
        $response = Http::get(HttpBin::url('/get'))->wait();

        expect($response->header('content-type'))->toContain('application/json')
            ->and($response->headers())->toHaveKey('content-type')
            ->and($response->headers())->toHaveKey('date')
        ;
    });

    test('handles multiple sequential requests', function () {
        $getResponse = Http::get(HttpBin::url('/get'))->wait();
        $postResponse = Http::withJson(['key' => 'value'])->post(HttpBin::url('/post'))->wait();
        $deleteResponse = Http::delete(HttpBin::url('/delete'))->wait();

        expect($getResponse->status())->toBe(200)
            ->and($postResponse->status())->toBe(200)
            ->and($deleteResponse->status())->toBe(200)
        ;
    });

    test('sends custom headers and server echoes them back', function () {
        $response = Http::withHeaders([
            'X-Custom-Header' => 'test-value',
            'Accept' => 'application/json',
        ])
            ->get(HttpBin::url('/headers'))
            ->wait()
        ;

        expect($response->status())->toBe(200)
            ->and($response->json('headers.X-Custom-Header.0'))->toBe('test-value')
        ;
    });

    test('handles timeout configuration', function () {
        $response = Http::timeout(30)
            ->connectTimeout(10)
            ->get(HttpBin::url('/get'))
            ->wait()
        ;

        expect($response->status())->toBe(200);
    });

    test('validates response body as string', function () {
        $response = Http::get(HttpBin::url('/get'))->wait();

        $body = $response->body();

        expect($body)->toBeString()
            ->and(strlen($body))->toBeGreaterThan(0)
            ->and($body)->toContain('"url"')
            ->and($body)->toContain('"headers"')
        ;
    });

    test('checks HTTP version information', function () {
        $response = Http::get(HttpBin::url('/get'))->wait();

        expect($response->status())->toBe(200)
            ->and($response->getHttpVersion())->not->toBeNull()
        ;
    });

    test('posts form data', function () {
        $response = Http::asForm()
            ->withForm([
                'title' => 'Form Data Post',
                'body' => 'Posted via form data',
                'userId' => '1',
            ])
            ->post(HttpBin::url('/post'))
            ->wait()
        ;

        expect($response->status())->toBe(200)
            ->and($response->json('form.title.0'))->toBe('Form Data Post')
            ->and($response->json('form.userId.0'))->toBe('1')
        ;
    });

    test('handles delayed response', function () {
        $start = microtime(true);
        $response = Http::get(HttpBin::url('/delay/1'))->wait();
        $duration = microtime(true) - $start;

        expect($response->status())->toBe(200)
            ->and($duration)->toBeGreaterThanOrEqual(1.0)
        ;
    });

    test('handles gzipped response', function () {
        $response = Http::get(HttpBin::url('/gzip'))->wait();

        expect($response->status())->toBe(200)
            ->and($response->json('gzipped'))->toBeTrue()
        ;
    });

    test('follows redirects', function () {
        $response = Http::redirects(true, 5)
            ->get(HttpBin::url('/absolute-redirect/2'))
            ->wait()
        ;

        expect($response->status())->toBe(200)
            ->and($response->successful())->toBeTrue()
        ;
    });

    test('does not follow redirects when disabled', function () {
        $response = Http::redirects(false)
            ->get(HttpBin::url('/absolute-redirect/1'))
            ->wait()
        ;

        expect($response->status())->toBe(302);
    });
});

describe('Mock Handler Integration Tests', function () {

    test('simulates a GET response with mocks', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url(HttpBin::url('/get'))
            ->respondJson([
                'url' => HttpBin::url('/get'),
                'headers' => ['Host' => HttpBin::host()],
            ])
            ->register()
        ;

        $response = (new HttpClient())
            ->setHandler($handler)
            ->get(HttpBin::url('/get'))
            ->wait()
        ;

        expect($response->status())->toBe(200)
            ->and($response->json('url'))->toBe(HttpBin::url('/get'))
        ;
    });

    test('simulates slow API response', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url(HttpBin::url('/delay/1'))
            ->latency(0.5)
            ->respondJson(['url' => HttpBin::url('/delay/1')])
            ->register()
        ;

        $start = microtime(true);

        $response = (new HttpClient())
            ->setHandler($handler)
            ->get(HttpBin::url('/delay/1'))
            ->wait()
        ;

        $duration = microtime(true) - $start;

        expect($duration)->toBeGreaterThanOrEqual(0.5)
            ->and($response->json('url'))->toBe(HttpBin::url('/delay/1'))
        ;
    });

    test('simulates rate limiting scenario', function () {
        $handler = testingHttpHandler();

        $handler->mock('POST')
            ->url(HttpBin::url('/post'))
            ->rateLimitedUntilAttempt(3)
            ->register()
        ;

        $response = (new HttpClient())
            ->setHandler($handler)
            ->retry(5, 0.01)
            ->withJson(['title' => 'Test', 'body' => 'Test', 'userId' => 1])
            ->post(HttpBin::url('/post'))
            ->wait()
        ;

        expect($response->status())->toBe(200)
            ->and($response->json())->toHaveKey('success', true)
        ;
    });

    test('simulates network recovery scenario', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url(HttpBin::url('/get'))
            ->slowlyImproveUntilAttempt(3, 2.0)
            ->register()
        ;

        $response = (new HttpClient())
            ->setHandler($handler)
            ->retry(5, 0.01)
            ->get(HttpBin::url('/get'))
            ->wait()
        ;

        expect($response->status())->toBe(200)
            ->and($response->json())->toHaveKey('success', true)
        ;
    });

    test('simulates persistent mock for multiple requests', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url(HttpBin::url('/get') . '*')
            ->respondJson(['url' => HttpBin::url('/get'), 'mocked' => true])
            ->persistent()
            ->register()
        ;

        $client = (new HttpClient())->setHandler($handler);

        $response1 = $client->get(HttpBin::url('/get') . '?page=1')->wait();
        $response2 = $client->get(HttpBin::url('/get') . '?page=2')->wait();
        $response3 = $client->get(HttpBin::url('/get') . '?page=3')->wait();

        expect($response1->json('mocked'))->toBeTrue()
            ->and($response2->json('mocked'))->toBeTrue()
            ->and($response3->json('mocked'))->toBeTrue()
            ->and($handler->getRequestHistory())->toHaveCount(3)
        ;
    });
});
