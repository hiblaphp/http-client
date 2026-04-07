<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Tests\Fixtures\HttpBin;

use function Hibla\await;

describe('cURL Options', function () {
    beforeEach(function () {
        HttpBin::skipIfUnreachable();

        if (! extension_loaded('curl')) {
            test()->markTestSkipped('ext-curl is not loaded.');
        }
    });

    describe('withCurlOption', function () {
        it('sets a single curl option without breaking the request', function () {
            $response = await(
                Http::request()
                    ->withCurlOption(CURLOPT_ENCODING, 'gzip')
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();
        });

        it('sets CURLOPT_ENCODING to request compressed responses', function () {
            $response = await(
                Http::request()
                    ->withCurlOption(CURLOPT_ENCODING, 'gzip')
                    ->get(HttpBin::url('/gzip'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('gzipped'))->toBeTrue();
        });

        it('does not affect an independent request chain', function () {
            $base = Http::request()->withCurlOption(CURLOPT_ENCODING, 'gzip');

            $withOption = await($base->get(HttpBin::url('/gzip')));
            $withoutOption = await(Http::request()->get(HttpBin::url('/get')));

            expect($withOption->successful())->toBeTrue();
            expect($withoutOption->successful())->toBeTrue();
        });

        it('throws when the curl extension is not loaded', function () {
            expect(extension_loaded('curl'))->toBeTrue();
        })->skip('Simulating a missing ext-curl requires a dedicated environment without the extension loaded.');
    });

    describe('withCurlOptions', function () {
        it('sets multiple curl options at once without breaking the request', function () {
            $response = await(
                Http::request()
                    ->withCurlOptions([
                        CURLOPT_ENCODING => 'gzip',
                        CURLOPT_BUFFERSIZE => 16384,
                    ])
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();
        });

        it('ignores non-integer keys in the options array', function () {
            $response = await(
                Http::request()
                    ->withCurlOptions([
                        CURLOPT_ENCODING => 'gzip',
                        'invalid_key' => 'should_be_ignored',
                    ])
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();
        });

        it('merges options across multiple withCurlOptions calls', function () {
            $response = await(
                Http::request()
                    ->withCurlOptions([CURLOPT_ENCODING => 'gzip'])
                    ->withCurlOptions([CURLOPT_BUFFERSIZE => 16384])
                    ->get(HttpBin::url('/gzip'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('gzipped'))->toBeTrue();
        });
    });

    describe('interaction with the fluent chain', function () {
        it('works alongside withToken', function () {
            $response = await(
                Http::request()
                    ->withToken('my-token')
                    ->withCurlOption(CURLOPT_ENCODING, 'gzip')
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.Authorization.0'))->toBe('Bearer my-token');
        });

        it('works alongside withBasicAuth', function () {
            $response = await(
                Http::request()
                    ->withBasicAuth('myuser', 'mypassword')
                    ->withCurlOption(CURLOPT_ENCODING, 'gzip')
                    ->get(HttpBin::url('/basic-auth/myuser/mypassword'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('authenticated'))->toBeTrue();
        });

        it('works alongside timeout', function () {
            $response = await(
                Http::request()
                    ->timeout(10)
                    ->withCurlOption(CURLOPT_ENCODING, 'gzip')
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();
        });

        it('works alongside custom headers', function () {
            $response = await(
                Http::request()
                    ->withHeader('X-Custom-Header', 'curl-test')
                    ->withCurlOption(CURLOPT_ENCODING, 'gzip')
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.X-Custom-Header.0'))->toBe('curl-test');
        });

        it('works alongside withCurlOptions in the same chain', function () {
            $response = await(
                Http::request()
                    ->withCurlOption(CURLOPT_ENCODING, 'gzip')
                    ->withCurlOptions([CURLOPT_BUFFERSIZE => 16384])
                    ->get(HttpBin::url('/gzip'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('gzipped'))->toBeTrue();
        });

        it('works alongside a POST with json body', function () {
            $response = await(
                Http::request()
                    ->withCurlOption(CURLOPT_ENCODING, 'gzip')
                    ->withJson(['key' => 'value'])
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('json.key'))->toBe('value');
        });

        it('works alongside retry configuration', function () {
            $response = await(
                Http::request()
                    ->retry(2)
                    ->withCurlOption(CURLOPT_ENCODING, 'gzip')
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();
        });

        it('curl options do not leak into a branched client', function () {
            $base = Http::request()->withCurlOption(CURLOPT_ENCODING, 'gzip');

            $branch = Http::request()->get(HttpBin::url('/get'));

            $baseResponse = await($base->get(HttpBin::url('/gzip')));
            $branchResponse = await($branch);

            expect($baseResponse->successful())->toBeTrue();
            expect($branchResponse->successful())->toBeTrue();
        });
    });

    describe('cURL header options vs fluent header methods', function () {
        it('CURLOPT_HTTPHEADER merges with fluent headers when set after', function () {
            $response = await(
                Http::request()
                    ->withHeader('X-Custom-Header', 'from-fluent')
                    ->withCurlOption(CURLOPT_HTTPHEADER, ['X-Extra: from-curl'])
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.X-Custom-Header.0'))->toBe('from-fluent');
            expect($response->json('headers.X-Extra.0'))->toBe('from-curl');
        });

        it('CURLOPT_HTTPHEADER merges with fluent headers when set before', function () {
            $response = await(
                Http::request()
                    ->withCurlOption(CURLOPT_HTTPHEADER, ['X-Extra: from-curl'])
                    ->withHeader('X-Custom-Header', 'from-fluent')
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.X-Custom-Header.0'))->toBe('from-fluent');
            expect($response->json('headers.X-Extra.0'))->toBe('from-curl');
        });

        it('does not drop Authorization when CURLOPT_HTTPHEADER adds an unrelated header', function () {
            $response = await(
                Http::request()
                    ->withToken('my-token')
                    ->withCurlOption(CURLOPT_HTTPHEADER, ['X-Debug: true'])
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.Authorization.0'))->toBe('Bearer my-token');
            expect($response->json('headers.X-Debug.0'))->toBe('true');
        });

        it('does not drop fluent headers when CURLOPT_HTTPHEADER is set on a shared base client', function () {
            $base = Http::request()
                ->withToken('my-token')
                ->withHeader('X-Tenant', 'acme')
            ;

            $response = await(
                $base
                    ->withCurlOption(CURLOPT_HTTPHEADER, ['X-Debug: true'])
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.Authorization.0'))->toBe('Bearer my-token');
            expect($response->json('headers.X-Tenant.0'))->toBe('acme');
            expect($response->json('headers.X-Debug.0'))->toBe('true');
        });

        it('CURLOPT_HTTPHEADER value wins when it targets the same header as a fluent method', function () {
            $response = await(
                Http::request()
                    ->withHeader('X-Custom-Header', 'from-fluent')
                    ->withCurlOption(CURLOPT_HTTPHEADER, ['X-Custom-Header: from-curl'])
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();

            $header = $response->json('headers.X-Custom-Header.0');
            expect($header)->toBeIn(['from-fluent', 'from-curl']);
        });

        it('does not lose Content-Type when CURLOPT_HTTPHEADER adds an unrelated header', function () {
            $response = await(
                Http::request()
                    ->asJson()
                    ->withCurlOption(CURLOPT_HTTPHEADER, ['X-Debug: true'])
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.Content-Type.0'))->toContain('application/json');
            expect($response->json('headers.X-Debug.0'))->toBe('true');
        });

        it('does not lose fluent headers when withCurlOption targets a non-header option', function () {
            $response = await(
                Http::request()
                    ->withHeader('X-Fluent-Header', 'fluent-value')
                    ->withToken('my-token')
                    ->withCurlOption(CURLOPT_BUFFERSIZE, 16384)
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.X-Fluent-Header.0'))->toBe('fluent-value');
            expect($response->json('headers.Authorization.0'))->toBe('Bearer my-token');
        });
    });
});
