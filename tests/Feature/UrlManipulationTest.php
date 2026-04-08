<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Tests\Fixtures\HttpBin;

use function Hibla\await;

describe('URL Parameter Expansion', function () {
    beforeEach(function () {
        HttpBin::skipIfUnreachable();
    });

    describe('withUrlParameter', function () {
        it('expands a single simple parameter', function () {
            $response = await(
                Http::client()
                    ->withUrlParameter('path', 'my-resource')
                    ->get(HttpBin::url('/anything/{path}'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('url'))->toContain('/anything/my-resource');
        });

        it('percent-encodes special characters in simple expansion', function () {
            $response = await(
                Http::client()
                    ->withUrlParameter('q', 'hello world')
                    ->get(HttpBin::url('/get?q={q}'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('args.q.0'))->toBe('hello world');
        });

        it('preserves special characters with reserved expansion {+param}', function () {
            $response = await(
                Http::client()
                    ->withUrlParameter('path', 'foo/bar/baz')
                    ->get(HttpBin::url('/anything/{+path}'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('url'))->toContain('/anything/foo/bar/baz');
        });

        it('leaves unmatched placeholders untouched', function () {
            $response = await(
                Http::client()
                    ->withUrlParameter('known', 'get')
                    ->get(HttpBin::url('/{known}?x={unknown}'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('url'))->toContain('{unknown}');
        });

        it('overrides a parameter when called multiple times', function () {
            $response = await(
                Http::client()
                    ->withUrlParameter('path', 'anything')
                    ->withUrlParameter('path', 'get')
                    ->get(HttpBin::url('/{path}'))
            );

            expect($response->json('url'))->toContain('/get');
        });

        it('works with different HTTP methods', function () {
            $tempFile = sys_get_temp_dir() . '/url_param_post.txt';
            file_put_contents($tempFile, 'data');

            $postResponse = await(
                Http::client()
                    ->withUrlParameter('path', 'post')
                    ->post(HttpBin::url('/{path}'))
            );

            expect($postResponse->successful())->toBeTrue();
            expect($postResponse->json('url'))->toContain('/post');

            $deleteResponse = await(
                Http::client()
                    ->withUrlParameter('path', 'delete')
                    ->delete(HttpBin::url('/{path}'))
            );

            expect($deleteResponse->successful())->toBeTrue();
            expect($deleteResponse->json('url'))->toContain('/delete');

            @unlink($tempFile);
        });
    });

    describe('withUrlParameters', function () {
        it('expands multiple parameters at once', function () {
            $response = await(
                Http::client()
                    ->withUrlParameters([
                        'resource' => 'anything',
                        'id' => '42',
                    ])
                    ->get(HttpBin::url('/{resource}/{id}'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('url'))->toContain('/anything/42');
        });

        it('merges parameters across multiple withUrlParameters calls', function () {
            $response = await(
                Http::client()
                    ->withUrlParameters(['resource' => 'anything'])
                    ->withUrlParameters(['id' => '99'])
                    ->get(HttpBin::url('/{resource}/{id}'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('url'))->toContain('/anything/99');
        });

        it('later call overwrites a previously set parameter', function () {
            $response = await(
                Http::client()
                    ->withUrlParameters(['id' => 'first'])
                    ->withUrlParameters(['id' => 'second'])
                    ->get(HttpBin::url('/anything/{id}'))
            );

            expect($response->json('url'))->toContain('/anything/second');
        });

        it('handles an empty parameters array without error', function () {
            $response = await(
                Http::client()
                    ->withUrlParameters([])
                    ->get(HttpBin::url('/get'))
            );

            expect($response->successful())->toBeTrue();
        });

        it('percent-encodes values in simple placeholders', function () {
            $response = await(
                Http::client()
                    ->withUrlParameters([
                        'resource' => 'anything',
                        'tag' => 'foo bar',
                    ])
                    ->get(HttpBin::url('/{resource}?tag={tag}'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('args.tag.0'))->toBe('foo bar');
        });

        it('preserves slashes with reserved expansion across multiple params', function () {
            $response = await(
                Http::client()
                    ->withUrlParameters([
                        'base' => 'anything',
                        'path' => 'foo/bar',
                    ])
                    ->get(HttpBin::url('/{base}/{+path}'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('url'))->toContain('/anything/foo/bar');
        });
    });

    describe('mixing withUrlParameter and withUrlParameters', function () {
        it('correctly merges singular and plural calls', function () {
            $response = await(
                Http::client()
                    ->withUrlParameter('resource', 'anything')
                    ->withUrlParameters(['id' => '7', 'extra' => 'test'])
                    ->get(HttpBin::url('/{resource}/{id}/{extra}'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('url'))->toContain('/anything/7/test');
        });
    });
});
