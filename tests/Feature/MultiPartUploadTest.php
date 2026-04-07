<?php

use Hibla\HttpClient\Http;
use Tests\Fixtures\HttpBin;

use function Hibla\await;

describe('Real Network Multipart Uploads', function () {
    beforeEach(function () {
        HttpBin::skipIfUnreachable();
    });

    describe('basic uploads', function () {
        it('successfully uploads files and fields to httpbin', function () {
            $tempFile = sys_get_temp_dir() . '/real_upload_test.txt';
            $fileContent = 'This is real data being sent over the internet!';
            file_put_contents($tempFile, $fileContent);

            $response = await(
                Http::request()
                    ->withMultipart([
                        'project' => 'Hibla',
                        'version' => '1.0.0',
                    ])
                    ->withFile('document', $tempFile)
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();

            expect($response->json('form.project.0'))->toBe('Hibla')
                ->and($response->json('form.version.0'))->toBe('1.0.0')
                ->and($response->json('files.document.0'))->toBe($fileContent);

            @unlink($tempFile);
        });

        it('uploads multiple files in a single request', function () {
            $files = [];
            $contents = [];

            foreach (['alpha', 'beta', 'gamma'] as $name) {
                $path = sys_get_temp_dir() . "/{$name}_upload.txt";
                $contents[$name] = "Content of {$name}";
                file_put_contents($path, $contents[$name]);
                $files[$name] = $path;
            }

            $response = await(
                Http::request()
                    ->withMultipart(['batch' => 'true'])
                    ->withFiles($files)
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();

            foreach ($contents as $name => $content) {
                expect($response->json("files.{$name}.0"))->toBe($content);
            }

            foreach ($files as $path) {
                @unlink($path);
            }
        });

        it('uploads a file with a custom filename and content type', function () {
            $tempFile = sys_get_temp_dir() . '/raw_data.bin';
            file_put_contents($tempFile, 'binary-ish content');

            $response = await(
                Http::request()
                    ->withFile('asset', $tempFile, 'renamed.txt', 'text/plain')
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('files.asset.0'))->toBe('binary-ish content');

            @unlink($tempFile);
        });

        it('uploads only form fields with no file', function () {
            $response = await(
                Http::request()
                    ->withMultipart([
                        'field_a' => 'value_a',
                        'field_b' => 'value_b',
                    ])
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('form.field_a.0'))->toBe('value_a')
                ->and($response->json('form.field_b.0'))->toBe('value_b');
        });
    });

    describe('file content edge cases', function () {
        it('uploads an empty file', function () {
            $tempFile = sys_get_temp_dir() . '/empty_upload.txt';
            file_put_contents($tempFile, '');

            $response = await(
                Http::request()
                    ->withFile('empty', $tempFile)
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('files.empty.0'))->toBe('');

            @unlink($tempFile);
        });

        it('uploads a file with unicode content', function () {
            $tempFile = sys_get_temp_dir() . '/unicode_upload.txt';
            $content = 'こんにちは — héllo wörld — 你好';
            file_put_contents($tempFile, $content);

            $response = await(
                Http::request()
                    ->withFile('unicode_file', $tempFile)
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('files.unicode_file.0'))->toBe($content);

            @unlink($tempFile);
        });

        it('uploads a large file within httpbin limits', function () {
            $tempFile = sys_get_temp_dir() . '/large_upload.txt';
            $content = str_repeat('A', 512 * 1024); // 512 KB — safe within httpbin's limit
            file_put_contents($tempFile, $content);

            $response = await(
                Http::request()
                    ->timeout(60)
                    ->withFile('large', $tempFile)
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('files.large.0'))->toBe($content);

            @unlink($tempFile);
        });

        it('uploads a file with special characters in the content', function () {
            $tempFile = sys_get_temp_dir() . '/special_chars.txt';
            $content = "line1\r\nline2\nnull:\x00tab:\there";
            file_put_contents($tempFile, $content);

            $response = await(
                Http::request()
                    ->withFile('special', $tempFile)
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();

            @unlink($tempFile);
        });

        it('uploads multipart with unicode field values', function () {
            $response = await(
                Http::request()
                    ->withMultipart([
                        'description' => '日本語テスト',
                        'emoji'       => '🚀🎉',
                    ])
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('form.description.0'))->toBe('日本語テスト')
                ->and($response->json('form.emoji.0'))->toBe('🚀🎉');
        });
    });

    describe('field value edge cases', function () {
        it('uploads multipart with numeric and boolean-like string field values', function () {
            $response = await(
                Http::request()
                    ->withMultipart([
                        'count' => '0',
                        'flag'  => 'false',
                        'pi'    => '3.14',
                        'empty' => '',
                    ])
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('form.count.0'))->toBe('0')
                ->and($response->json('form.flag.0'))->toBe('false')
                ->and($response->json('form.pi.0'))->toBe('3.14')
                ->and($response->json('form.empty.0'))->toBe('');
        });

        it('uploads multipart with a very long field value', function () {
            $longValue = str_repeat('x', 10_000);

            $response = await(
                Http::request()
                    ->withMultipart(['long_field' => $longValue])
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('form.long_field.0'))->toBe($longValue);
        });
    });

    describe('request configuration', function () {
        it('sends custom headers alongside a multipart upload', function () {
            $tempFile = sys_get_temp_dir() . '/header_test.txt';
            file_put_contents($tempFile, 'header test');

            $response = await(
                Http::request()
                    ->withHeader('X-Custom-Header', 'hibla-test')
                    ->withFile('doc', $tempFile)
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.X-Custom-Header.0'))->toBe('hibla-test');

            @unlink($tempFile);
        });

        it('sends a bearer token alongside a multipart upload', function () {
            $tempFile = sys_get_temp_dir() . '/auth_test.txt';
            file_put_contents($tempFile, 'auth test content');

            $response = await(
                Http::request()
                    ->withToken('super-secret-token')
                    ->withFile('doc', $tempFile)
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.Authorization.0'))->toBe('Bearer super-secret-token');

            @unlink($tempFile);
        });

        it('uploads with basic auth', function () {
            $tempFile = sys_get_temp_dir() . '/basic_auth_test.txt';
            file_put_contents($tempFile, 'basic auth test');

            $response = await(
                Http::request()
                    ->withBasicAuth('user', 'pass')
                    ->withFile('doc', $tempFile)
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('headers.Authorization.0'))->toStartWith('Basic ');

            @unlink($tempFile);
        });
    });

    describe('error and boundary conditions', function () {
        it('throws when given a non-existent file path', function () {
            expect(fn() =>
                Http::request()->withFile('ghost', '/tmp/does_not_exist_hibla.txt')
            )->toThrow(\InvalidArgumentException::class);
        });

        it('returns a non-2xx status for a bad endpoint gracefully', function () {
            $tempFile = sys_get_temp_dir() . '/bad_endpoint.txt';
            file_put_contents($tempFile, 'data');

            $response = await(
                Http::request()
                    ->withFile('doc', $tempFile)
                    ->post(HttpBin::url('/status/422'))
            );

            expect($response->status())->toBe(422);
            expect($response->successful())->toBeFalse();

            @unlink($tempFile);
        });

        it('uploads with multipartWithFiles helper', function () {
            $tempFile = sys_get_temp_dir() . '/helper_test.txt';
            $content = 'helper method test';
            file_put_contents($tempFile, $content);

            $response = await(
                Http::request()
                    ->multipartWithFiles(
                        data: ['source' => 'helper'],
                        files: ['doc' => $tempFile],
                    )
                    ->post(HttpBin::url('/post'))
            );

            expect($response->successful())->toBeTrue();
            expect($response->json('form.source.0'))->toBe('helper')
                ->and($response->json('files.doc.0'))->toBe($content);

            @unlink($tempFile);
        });
    });
});