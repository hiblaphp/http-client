<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Interfaces\RequestInterface;
use Hibla\HttpClient\Interfaces\ResponseInterface;
use Hibla\HttpClient\Response;
use Tests\Fixtures\HttpBin;

beforeEach(function () {
    HttpBin::skipIfUnreachable();
});

describe('Advanced Response Interception', function () {

    it('can transform a 404 Not Found into a successful default response', function () {
        $response = Http::request()
            ->interceptResponse(function (ResponseInterface $res) {
                if ($res->status() === 404) {
                    return new Response(
                        json_encode(['error' => false, 'message' => 'Resource not found, but here is a fallback']),
                        200,
                        ['Content-Type' => 'application/json']
                    );
                }
                return $res;
            })
            ->get(HttpBin::url('/status/404'))
            ->wait();

        expect($response->status())->toBe(200)
            ->and($response->json('message'))->toBe('Resource not found, but here is a fallback')
            ->and($response->json('error'))->toBeFalse();
    });

    it('can throw custom exceptions based on status codes', function () {
        $client = Http::request()
            ->interceptResponse(function (ResponseInterface $res) {
                if ($res->status() === 418) {
                    throw new \RuntimeException("I refuse to make coffee: I am a teapot.");
                }
                return $res;
            });

        expect(fn() => $client->get(HttpBin::url('/status/418'))->wait())
            ->toThrow(\RuntimeException::class, 'I refuse to make coffee');
    });

    it('can implement a manual retry on 401 Unauthorized using the full pipeline', function () {
        $attempts = 0;

        $response = Http::request()
            ->intercept(function (RequestInterface $request, callable $next) use (&$attempts) {
                $attempts++;
                
                return $next($request)->then(function (ResponseInterface $response) use ($request, $next, &$attempts) {
                    if ($response->status() === 401) {
                        $attempts++; 
                        $authenticatedRequest = $request->withHeader('Authorization', 'Bearer refreshed-token');
                        return $next($authenticatedRequest);
                    }
                    
                    return $response;
                });
            })
            ->get(HttpBin::url('/status/401'))
            ->wait();

        expect($attempts)->toBe(2)
            ->and($response->status())->toBe(401); 
    });

    it('can standardize error structures from different status codes', function () {
        $client = Http::request()
            ->interceptResponse(function (ResponseInterface $res) {
                if ($res->failed()) {
                    $originalBody = $res->json();
                    return new Response(
                        json_encode([
                            'status' => 'error',
                            'http_code' => $res->status(),
                            'debug' => $originalBody
                        ]),
                        $res->status(),
                        ['Content-Type' => 'application/json']
                    );
                }
                return $res;
            });

        $res500 = $client->get(HttpBin::url('/status/500'))->wait();
        expect($res500->json('status'))->toBe('error')
            ->and($res500->json('http_code'))->toBe(500);

        $res400 = $client->get(HttpBin::url('/status/400'))->wait();
        expect($res400->json('status'))->toBe('error')
            ->and($res400->json('http_code'))->toBe(400);
    });

    it('can log or track metrics based on successful responses', function () {
        $metrics = ['success_count' => 0];

        $client = Http::request()
            ->interceptResponse(function (ResponseInterface $res) use (&$metrics) {
                if ($res->successful()) {
                    $metrics['success_count']++;
                }
                return $res;
            });

        $client->get(HttpBin::url('/status/200'))->wait();
        $client->get(HttpBin::url('/status/201'))->wait();
        $client->get(HttpBin::url('/status/404'))->wait();

        expect($metrics['success_count'])->toBe(2);
    });

    it('handles redirects manually by intercepting 3xx status codes', function () {
        $redirectUrl = null;

        $response = Http::request()
            ->redirects(false)
            ->interceptResponse(function (ResponseInterface $res) use (&$redirectUrl) {
                if ($res->status() >= 300 && $res->status() < 400) {
                    $redirectUrl = $res->header('Location');
                }
                return $res;
            })
            ->get(HttpBin::url('/redirect/1'))
            ->wait();

        expect($response->status())->toBe(302)
            ->and($redirectUrl)->toContain('/get');
    });
});