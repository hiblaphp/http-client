<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Hibla\HttpClient\ValueObjects\DownloadProgress;
use Hibla\HttpClient\ValueObjects\UploadProgress;
use Tests\Fixtures\HttpBin;

use function Hibla\await;

describe('Uploads and Downloads', function () {

    beforeEach(function () {
        HttpBin::skipIfUnreachable();
    });

    describe('Downloads', function () {

        it('downloads a text response to a file successfully', function () {
            $dest = sys_get_temp_dir() . '/hibla_dl_text_' . uniqid() . '.txt';

            try {
                $result = await(
                    Http::client()->download(HttpBin::url('/base64/SGVsbG8gV29ybGQ='), $dest)
                );

                expect($result['status'])->toBe(200);
                expect(file_exists($dest))->toBeTrue();
                expect(file_get_contents($dest))->toBe('Hello World');
            } finally {
                @unlink($dest);
            }
        });

        it('downloads a specific number of binary bytes to a file', function () {
            $dest = sys_get_temp_dir() . '/hibla_dl_bytes_' . uniqid() . '.bin';

            try {
                $result = await(
                    Http::client()->download(HttpBin::url('/bytes/1024'), $dest)
                );

                expect($result['status'])->toBe(200);
                expect(file_exists($dest))->toBeTrue();
                expect(filesize($dest))->toBe(1024);
            } finally {
                @unlink($dest);
            }
        });

        it('downloads an image successfully', function () {
            $dest = sys_get_temp_dir() . '/hibla_dl_image_' . uniqid() . '.png';

            try {
                $result = await(
                    Http::client()
                        ->withHeader('Accept', 'image/png')
                        ->download(HttpBin::url('/image/png'), $dest)
                );

                expect($result['status'])->toBe(200);
                expect(file_exists($dest))->toBeTrue();
                expect(filesize($dest))->toBeGreaterThan(0);
            } finally {
                @unlink($dest);
            }
        });

        it('tracks download progress correctly', function () {
            $dest = sys_get_temp_dir() . '/hibla_dl_prog_' . uniqid() . '.bin';
            $progressCalls = 0;

            try {
                $result = await(
                    Http::client()->download(
                        HttpBin::url('/bytes/1048576'),
                        $dest,
                        function ($progress) use (&$progressCalls) {
                            if ($progress instanceof DownloadProgress) {
                                $progressCalls++;
                            }
                        }
                    )
                );

                expect($result['status'])->toBe(200);
                expect(file_exists($dest))->toBeTrue();
                expect(filesize($dest))->toBe(1048576);
                expect($progressCalls)->toBeGreaterThan(0);
            } finally {
                @unlink($dest);
            }
        });
    });

    describe('Uploads', function () {

        it('uploads a file as a raw data stream (PUT)', function () {
            $source = sys_get_temp_dir() . '/hibla_ul_raw_' . uniqid() . '.txt';
            $content = 'This is raw payload data sent via a stream.';
            file_put_contents($source, $content);

            try {
                $result = await(
                    Http::client()->upload(HttpBin::url('/put'), $source)
                );

                expect($result['status'])->toBe(200);
            } finally {
                @unlink($source);
            }
        });

        it('uploads a file using multipart form data (POST)', function () {
            $source = sys_get_temp_dir() . '/hibla_ul_multi_' . uniqid() . '.txt';
            $content = 'This is file content sent inside a multipart form field.';
            file_put_contents($source, $content);

            try {
                $response = await(
                    Http::client()
                        ->withFile('upload_field', $source, 'custom_name.txt')
                        ->post(HttpBin::url('/post'))
                );

                expect($response->successful())->toBeTrue();
                expect($response->json('files.upload_field.0'))->toBe($content);
            } finally {
                @unlink($source);
            }
        });

        it('uploads multiple files simultaneously via multipart form data', function () {
            $file1 = sys_get_temp_dir() . '/hibla_multi_1_' . uniqid() . '.txt';
            $file2 = sys_get_temp_dir() . '/hibla_multi_2_' . uniqid() . '.txt';

            file_put_contents($file1, 'Content for file 1');
            file_put_contents($file2, 'Content for file 2');

            try {
                $response = await(
                    Http::client()
                        ->withFiles([
                            'doc_one' => $file1,
                            'doc_two' => $file2,
                        ])
                        ->withMultipart(['description' => 'Batch upload test'])
                        ->post(HttpBin::url('/post'))
                );

                expect($response->successful())->toBeTrue();
                expect($response->json('files.doc_one.0'))->toBe('Content for file 1');
                expect($response->json('files.doc_two.0'))->toBe('Content for file 2');
                expect($response->json('form.description.0'))->toBe('Batch upload test');
            } finally {
                @unlink($file1);
                @unlink($file2);
            }
        });

        it('tracks upload progress during a raw file upload', function () {
            $source = sys_get_temp_dir() . '/hibla_ul_prog_' . uniqid() . '.bin';

            file_put_contents($source, str_repeat('A', 1048576));
            $progressCalls = 0;

            try {
                $result = await(
                    Http::client()->upload(
                        HttpBin::url('/put'),
                        $source,
                        function ($progress) use (&$progressCalls) {
                            if ($progress instanceof UploadProgress) {
                                $progressCalls++;
                            }
                        }
                    )
                );

                expect($result['status'])->toBe(200);

                expect($progressCalls)->toBeGreaterThan(0);
            } finally {
                @unlink($source);
            }
        });
    });

    describe('Upload Memory Profile', function () {

        it('uploads a file without loading it into memory (Streaming PUT)', function () {
            $source = sys_get_temp_dir() . '/hibla_mem_test_' . uniqid() . '.bin';
            $size = 2 * 1024 * 1024;
            file_put_contents($source, str_repeat('A', $size));

            gc_collect_cycles();
            $memoryBefore = memory_get_usage();

            $result = await(
                Http::client()
                    ->withHeader('Expect', '')
                    ->upload(HttpBin::url('/status/204'), $source)
            );

            gc_collect_cycles();
            $memoryAfter = memory_get_usage();
            $memoryDiff = $memoryAfter - $memoryBefore;

            try {
                expect($result['status'])->toBe(204);

                expect($memoryDiff)->toBeLessThan(200 * 1024);
            } finally {
                @unlink($source);
            }
        });

        it('remains memory efficient during multipart uploads using CURLFile', function () {
            $source = sys_get_temp_dir() . '/hibla_multi_mem_' . uniqid() . '.bin';
            $size = 2 * 1024 * 1024;
            file_put_contents($source, str_repeat('B', $size));

            gc_collect_cycles();
            $memoryBefore = memory_get_usage();

            $response = await(
                Http::client()
                    ->withHeader('Expect', '')
                    ->withFile('heavy_file', $source)
                    ->post(HttpBin::url('/status/201'))
            );

            gc_collect_cycles();
            $memoryAfter = memory_get_usage();
            $memoryDiff = $memoryAfter - $memoryBefore;

            try {
                expect($response->status())->toBe(201);

                expect($memoryDiff)->toBeLessThan(200 * 1024);
            } finally {
                @unlink($source);
            }
        });
    });
});
