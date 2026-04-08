<?php

declare(strict_types=1);

namespace Tests\Integration;

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Interfaces\RequestInterface;
use Tests\Fixtures\HttpBin;

use function Hibla\delay;

beforeEach(function () {
    HttpBin::skipIfUnreachable();
});

describe('Request Interceptor Integration (HttpBin)', function () {

    it('successfully injects custom headers into the outgoing network request', function () {
        $response = Http::client()
            ->interceptRequest(fn (RequestInterface $r) => $r->withHeader('X-Integration-Test', 'hibla-v1'))
            ->get(HttpBin::url('/headers'))
            ->wait()
        ;

        expect($response->json('headers.X-Integration-Test.0'))->toBe('hibla-v1');
    });

    it('rewrites the URL path before the request is dispatched', function () {
        $response = Http::client()
            ->interceptRequest(function (RequestInterface $request) {
                $uri = $request->getUri();
                if ($uri->getPath() === '/anything') {
                    return $request->withUri($uri->withPath('/get'));
                }

                return $request;
            })
            ->get(HttpBin::url('/anything'))
            ->wait()
        ;

        expect($response->json('url'))->toEndWith('/get');
    });

    it('injects dynamic authentication tokens asynchronously via Promise', function () {
        $response = Http::client()
            ->interceptRequest(function (RequestInterface $request) {
                return delay(0.05)->then(fn () => $request->withToken('real-network-token-123'));
            })
            ->get(HttpBin::url('/bearer'))
            ->wait()
        ;

        expect($response->status())->toBe(200)
            ->and($response->json('authenticated'))->toBeTrue()
            ->and($response->json('token'))->toBe('real-network-token-123')
        ;
    });

    it('modifies the request body for POST requests', function () {
        $response = Http::client()
            ->interceptRequest(function (RequestInterface $request) {
                $content = $request->getBody()->getContents();

                return $request->body($content . '-modified-by-interceptor');
            })
            ->body('original-data')
            ->post(HttpBin::url('/post'))
            ->wait()
        ;

        expect($response->json('data'))->toBe('original-data-modified-by-interceptor');
    });

    it('appends global query parameters to the URI', function () {
        $response = Http::client()
            ->interceptRequest(function (RequestInterface $request) {
                $uri = $request->getUri();
                $query = $uri->getQuery();
                $separator = $query ? '&' : '';

                return $request->withUri($uri->withQuery($query . $separator . 'api_key=secret_value'));
            })
            ->get(HttpBin::url('/get'), ['search' => 'hibla'])
            ->wait()
        ;

        expect($response->json('args.search.0'))->toBe('hibla')
            ->and($response->json('args.api_key.0'))->toBe('secret_value')
        ;
    });

    it('accumulates changes through a chain of multiple interceptors', function () {
        $response = Http::client()
            ->interceptRequest(fn ($r) => $r->withHeader('X-Order', '1'))
            ->interceptRequest(fn ($r) => $r->withHeader('X-Order', $r->getHeaderLine('X-Order') . '2'))
            ->interceptRequest(fn ($r) => $r->withHeader('X-Order', $r->getHeaderLine('X-Order') . '3'))
            ->get(HttpBin::url('/headers'))
            ->wait()
        ;

        expect($response->json('headers.X-Order.0'))->toBe('123');
    });

    it('handles method-specific logic within a global interceptor', function () {
        $client = Http::client()
            ->interceptRequest(function (RequestInterface $request) {
                if ($request->getMethod() === 'DELETE') {
                    return $request->withHeader('X-Confirm-Delete', 'true');
                }

                return $request;
            })
        ;

        $resDelete = $client->delete(HttpBin::url('/delete'))->wait();
        expect($resDelete->json('headers.X-Confirm-Delete.0'))->toBe('true');

        $resGet = $client->get(HttpBin::url('/get'))->wait();
        expect($resGet->json('headers.X-Confirm-Delete'))->toBeNull();
    });
});
