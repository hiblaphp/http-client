<?php

declare(strict_types=1);

namespace Tests\Handlers\Curl;

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\CookieJar;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Handlers\Curl\StreamingHandler;
use Hibla\HttpClient\StreamingResponse;
use Hibla\HttpClient\ValueObjects\DownloadProgress;
use Hibla\HttpClient\ValueObjects\UploadProgress;
use Tests\Fixtures\HttpBin;

beforeEach(function () {
    HttpBin::skipIfUnreachable();
    Loop::reset();
});

afterEach(function () {
    Loop::reset();
});

describe('StreamingHandler', function () {

    describe('streamRequest', function () {

        it('resolves with a StreamingResponse and emits chunks', function () {
            $handler = new StreamingHandler();
            $chunks = '';

            $promise = $handler->streamRequest(
                HttpBin::url('/stream/3'),
                [],
                function (string $chunk) use (&$chunks) {
                    $chunks .= $chunk;
                }
            );

            $response = null;
            $promise->then(function ($res) use (&$response) {
                $response = $res;
                Loop::stop();
            });

            Loop::run();

            expect($response)->toBeInstanceOf(StreamingResponse::class);
            expect($response->status())->toBe(200);

            $lines = array_filter(explode("\n", trim($chunks)));
            expect(count($lines))->toBe(3);
        });

        it('persists Set-Cookie headers into the provided cookie jar', function () {
            $handler = new StreamingHandler();
            $jar = new CookieJar();

            $url = HttpBin::url('/response-headers?Set-Cookie=stream_test%3Dactivated%3B+Path%3D%2F');

            $promise = $handler->streamRequest($url, ['_cookie_jar' => $jar]);

            $promise->then(fn () => Loop::stop());
            Loop::run();

            $allCookies = $jar->getAllCookies();
            $found = false;
            foreach ($allCookies as $cookie) {
                if ($cookie->getName() === 'stream_test' && $cookie->getValue() === 'activated') {
                    $found = true;

                    break;
                }
            }

            expect($found)->toBeTrue();
        });

        it('cleans up temporary files on completion', function () {
            $handler = new StreamingHandler();
            $tmpFile = tempnam(sys_get_temp_dir(), 'hibla_tmp_');
            file_put_contents($tmpFile, 'cleanup test');

            $promise = $handler->streamRequest(HttpBin::url('/get'), [
                '_tmp_files' => [$tmpFile],
            ]);

            $promise->then(fn () => Loop::stop());
            Loop::run();

            expect(file_exists($tmpFile))->toBeFalse();
        });

        it('rejects with NetworkException on failure', function () {
            $handler = new StreamingHandler();
            $promise = $handler->streamRequest('http://127.0.0.1:19999', [CURLOPT_CONNECTTIMEOUT => 1]);

            $error = null;
            $promise->catch(function ($err) use (&$error) {
                $error = $err;
                Loop::stop();
            });

            Loop::run();

            expect($error)->toBeInstanceOf(NetworkException::class);
        });
    });

    describe('downloadFile', function () {

        it('downloads a response to a file', function () {
            $handler = new StreamingHandler();
            $dest = tempnam(sys_get_temp_dir(), 'hibla_dl_');

            $promise = $handler->downloadFile(HttpBin::url('/bytes/512'), $dest);

            $result = null;
            $promise->then(function ($res) use (&$result) {
                $result = $res;
                Loop::stop();
            });

            Loop::run();

            expect(file_exists($dest))->toBeTrue();
            expect(filesize($dest))->toBe(512);
            expect($result['status'])->toBe(200);

            unlink($dest);
        });

        it('reports download progress', function () {
            $handler = new StreamingHandler();
            $dest = tempnam(sys_get_temp_dir(), 'hibla_dl_');
            $progressCalled = false;

            $promise = $handler->downloadFile(
                HttpBin::url('/bytes/1024'),
                $dest,
                [],
                function ($progress) use (&$progressCalled) {
                    if ($progress instanceof DownloadProgress) {
                        $progressCalled = true;
                    }
                }
            );

            $promise->then(fn () => Loop::stop());
            Loop::run();

            expect($progressCalled)->toBeTrue();
            unlink($dest);
        });

        it('deletes destination file on cancellation', function () {
            $handler = new StreamingHandler();
            $dest = tempnam(sys_get_temp_dir(), 'hibla_dl_');

            $promise = $handler->downloadFile(HttpBin::url('/delay/2'), $dest);

            Loop::addTimer(0.1, function () use ($promise) {
                $promise->cancel();
                Loop::stop();
            });

            Loop::run();

            expect(file_exists($dest))->toBeFalse();
        });
    });

    describe('uploadFile', function () {

        it('uploads a file via PUT', function () {
            $handler = new StreamingHandler();
            $source = tempnam(sys_get_temp_dir(), 'hibla_ul_');
            $content = 'raw upload data';
            file_put_contents($source, $content);

            $promise = $handler->uploadFile(HttpBin::url('/put'), $source);

            $result = null;
            $promise->then(function ($res) use (&$result) {
                $result = $res;
                Loop::stop();
            });

            Loop::run();

            expect($result['status'])->toBe(200);
            unlink($source);
        });

        it('reports upload progress', function () {
            $handler = new StreamingHandler();
            $source = tempnam(sys_get_temp_dir(), 'hibla_ul_');
            file_put_contents($source, str_repeat('a', 10000));
            $progressCalled = false;

            $promise = $handler->uploadFile(
                HttpBin::url('/put'),
                $source,
                [],
                function ($progress) use (&$progressCalled) {
                    if ($progress instanceof UploadProgress) {
                        $progressCalled = true;
                    }
                }
            );

            $promise->then(fn () => Loop::stop());
            Loop::run();

            expect($progressCalled)->toBeTrue();
            unlink($source);
        });

        it('rejects if the source file does not exist', function () {
            $handler = new StreamingHandler();
            $promise = $handler->uploadFile(HttpBin::url('/put'), '/non/existent/file');

            $error = null;
            $promise->catch(function ($err) use (&$error) {
                $error = $err;
            });

            Loop::addTimer(0.01, fn () => Loop::stop());
            Loop::run();

            expect($error)->toBeInstanceOf(\Hibla\HttpClient\Exceptions\HttpStreamException::class);
            expect($error->getMessage())->toContain('Cannot open file for reading');
        });
    });
});
