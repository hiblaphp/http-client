<?php

declare(strict_types=1);

namespace Tests\Integration;

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Interfaces\RequestInterface;
use Hibla\HttpClient\Interfaces\ResponseInterface;
use Hibla\HttpClient\Interfaces\StreamingResponseInterface;
use Tests\Fixtures\HttpBin;

use function Hibla\delay;

beforeEach(function () {
    HttpBin::skipIfUnreachable();
    Http::startTesting()->enablePassthrough();
});

afterEach(function () {
    Http::stopTesting();
});

describe('Specialized Transport Interception', function () {

    describe('Streaming', function () {
        it('modifies headers of a streaming request', function () {
            $promise = Http::client()
                ->interceptRequest(fn (RequestInterface $r) => $r->withHeader('X-Stream-Test', 'active'))
                ->stream(HttpBin::url('/stream/1'))
            ;

            $response = $promise->wait();

            expect($response)->toBeInstanceOf(StreamingResponseInterface::class);
            Http::assertHeaderSent('X-Stream-Test', 'active');
        });

        it('allows interceptResponse to modify a StreamingResponse', function () {
            $promise = Http::client()
                ->interceptResponse(fn (ResponseInterface $res) => $res->withHeader('X-Stream-Processed', 'true'))
                ->stream(HttpBin::url('/stream/1'))
            ;
            $response = $promise->wait();

            expect($response->header('X-Stream-Processed'))->toBe('true');
        });
    });

    describe('Downloads', function () {
        it('modifies request headers for a download', function () {
            $dest = Http::getTestingHandler()->createTempFile('download.png');

            $promise = Http::client()
                ->interceptRequest(fn (RequestInterface $r) => $r->withHeader('X-Download-Token', 'abc-123'))
                ->download(HttpBin::url('/image/png'), $dest)
            ;

            $result = $promise->wait();

            expect($result['status'])->toBe(200);
            Http::assertHeaderSent('X-Download-Token', 'abc-123');
        });

        it('supports full pipeline interception for downloads (metadata array)', function () {
            $log = [];
            $dest = Http::getTestingHandler()->createTempFile('download.txt');

            $promise = Http::client()
                ->intercept(function (RequestInterface $request, callable $next) use (&$log) {
                    $log[] = 'starting-download';

                    return $next($request)->then(function (array $metadata) use (&$log) {
                        $log[] = 'finished-download';
                        $metadata['interceptor_note'] = 'verified';

                        return $metadata;
                    });
                })
                ->download(HttpBin::url('/bytes/10'), $dest)
            ;

            $result = $promise->wait();

            expect($log)->toBe(['starting-download', 'finished-download'])
                ->and($result['interceptor_note'])->toBe('verified')
                ->and($result['size'])->toBe(10)
            ;
        });
    });

    describe('Uploads', function () {
        it('modifies request headers and URL for an upload', function () {
            $source = Http::getTestingHandler()->createTempFile('upload.txt', 'upload-payload');

            $promise = Http::client()
                ->interceptRequest(function (RequestInterface $request) {
                    return $request->withUri($request->getUri()->withPath('/put'))
                                   ->withHeader('X-Upload-ID', 'up-99')
                    ;
                })
                ->upload(HttpBin::url('/anything'), $source)
            ;

            $result = $promise->wait();

            expect($result['status'])->toBe(200);

            Http::assertRequestMatchingUrl('PUT', HttpBin::url('/put'));
            Http::assertHeaderSent('X-Upload-ID', 'up-99');
        });

        it('supports async interceptors before starting an upload', function () {
            $source = Http::getTestingHandler()->createTempFile('upload.txt', 'payload');

            $startTime = microtime(true);
            $promise = Http::client()
                ->interceptRequest(fn ($r) => delay(0.1)->then(fn () => $r->withHeader('X-Waited', 'true')))
                ->upload(HttpBin::url('/put'), $source)
            ;

            $promise->wait();
            $duration = microtime(true) - $startTime;

            expect($duration)->toBeGreaterThanOrEqual(0.1);
            Http::assertHeaderSent('X-Waited', 'true');
        });
    });
});
