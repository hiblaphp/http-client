<?php

declare(strict_types=1);

use Hibla\HttpClient\CookieJar;
use Hibla\HttpClient\HttpClient;
use Hibla\HttpClient\ValueObjects\Cookie;
use Tests\Fixtures\HttpBin;

use function Hibla\await;

describe('Cookie Streaming Handling Integration Test', function () {

    beforeEach(function () {
        HttpBin::skipIfUnreachable();
    });

    describe('Streaming cookie handling', function () {

        test('jar cookie is sent during a streaming request', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'stream_token',
                value: 'streamed',
                domain: HttpBin::host(),
                path: '/',
            ));

            $chunks = '';

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->stream(HttpBin::url('/cookies'), function (string $chunk) use (&$chunks): void {
                        $chunks .= $chunk;
                    })
            );

            $data = json_decode($chunks, true);
            expect($data['cookies']['stream_token'])->toBe('streamed');
        });

        test('Set-Cookie header from a streaming response is stored in the jar', function () {
            $jar = new CookieJar();

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->stream(HttpBin::url('/response-headers?' . http_build_query([
                        'Set-Cookie' => 'stream_inbound=yes; Path=/; Domain=' . HttpBin::host(),
                    ])))
            );

            $cookies = array_values($jar->getCookies(HttpBin::host(), '/'));
            $names   = array_map(fn(Cookie $c) => $c->getName(), $cookies);

            expect($names)->toContain('stream_inbound');
        });

        test('cookie stored from a streaming response is replayed on the next regular request', function () {
            $jar = new CookieJar();

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->stream(HttpBin::url('/response-headers?' . http_build_query([
                        'Set-Cookie' => 'stream_replay=yes; Path=/; Domain=' . HttpBin::host(),
                    ])))
            );

            $response = await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->get(HttpBin::url('/cookies'))
            );

            expect($response->json('cookies.stream_replay'))->toBe('yes');
        });

        test('cookie stored from a streaming response is replayed on the next streaming request', function () {
            $jar = new CookieJar();

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->stream(HttpBin::url('/response-headers?' . http_build_query([
                        'Set-Cookie' => 'stream_chain=chained; Path=/; Domain=' . HttpBin::host(),
                    ])))
            );

            $chunks = '';
            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->stream(HttpBin::url('/cookies'), function (string $chunk) use (&$chunks): void {
                        $chunks .= $chunk;
                    })
            );

            $data = json_decode($chunks, true);
            expect($data['cookies']['stream_chain'])->toBe('chained');
        });

        test('manual cookie and jar cookie are both sent during a streaming request', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'jar_stream',
                value: 'fromjar',
                domain: HttpBin::host(),
                path: '/',
            ));

            $chunks = '';

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->withCookie('manual_stream', 'manual')
                    ->stream(HttpBin::url('/cookies'), function (string $chunk) use (&$chunks): void {
                        $chunks .= $chunk;
                    })
            );

            $data    = json_decode($chunks, true);
            $cookies = $data['cookies'];

            expect($cookies)->toHaveKey('jar_stream');
            expect($cookies)->toHaveKey('manual_stream');
        });

        test('expired jar cookie is not sent during a streaming request', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'stale_stream',
                value: 'old',
                expires: time() - 3600,
                domain: HttpBin::host(),
                path: '/',
            ));

            $chunks = '';

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->stream(HttpBin::url('/cookies'), function (string $chunk) use (&$chunks): void {
                        $chunks .= $chunk;
                    })
            );

            $data = json_decode($chunks, true);
            expect($data['cookies'])->not->toHaveKey('stale_stream');
        });
    });

    describe('Download cookie handling', function () {

        test('jar cookie is sent during a download request', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'download_token',
                value: 'downloading',
                domain: HttpBin::host(),
                path: '/',
            ));

            $destination = tempnam(sys_get_temp_dir(), 'hibla_dl_');

            try {
                await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->download(HttpBin::url('/cookies'), $destination)
                );

                $data = json_decode(file_get_contents($destination), true);
                expect($data['cookies']['download_token'])->toBe('downloading');
            } finally {
                if (file_exists($destination)) {
                    unlink($destination);
                }
            }
        });

        test('Set-Cookie header from a download response is stored in the jar', function () {
            $jar         = new CookieJar();
            $destination = tempnam(sys_get_temp_dir(), 'hibla_dl_');

            try {
                await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->download(
                            HttpBin::url('/response-headers?' . http_build_query([
                                'Set-Cookie' => 'download_inbound=yes; Path=/; Domain=' . HttpBin::host(),
                            ])),
                            $destination
                        )
                );

                $cookies = array_values($jar->getCookies(HttpBin::host(), '/'));
                $names   = array_map(fn(Cookie $c) => $c->getName(), $cookies);

                expect($names)->toContain('download_inbound');
            } finally {
                if (file_exists($destination)) {
                    unlink($destination);
                }
            }
        });

        test('cookie stored from a download response is replayed on the next regular request', function () {
            $jar         = new CookieJar();
            $destination = tempnam(sys_get_temp_dir(), 'hibla_dl_');

            try {
                await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->download(
                            HttpBin::url('/response-headers?' . http_build_query([
                                'Set-Cookie' => 'download_replay=yes; Path=/; Domain=' . HttpBin::host(),
                            ])),
                            $destination
                        )
                );

                $response = await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->get(HttpBin::url('/cookies'))
                );

                expect($response->json('cookies.download_replay'))->toBe('yes');
            } finally {
                if (file_exists($destination)) {
                    unlink($destination);
                }
            }
        });

        test('cookie stored from a download response is replayed on the next download request', function () {
            $jar          = new CookieJar();
            $destination1 = tempnam(sys_get_temp_dir(), 'hibla_dl_');
            $destination2 = tempnam(sys_get_temp_dir(), 'hibla_dl_');

            try {
                await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->download(
                            HttpBin::url('/response-headers?' . http_build_query([
                                'Set-Cookie' => 'dl_chain=chained; Path=/; Domain=' . HttpBin::host(),
                            ])),
                            $destination1
                        )
                );

                await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->download(HttpBin::url('/cookies'), $destination2)
                );

                $data = json_decode(file_get_contents($destination2), true);
                expect($data['cookies']['dl_chain'])->toBe('chained');
            } finally {
                foreach ([$destination1, $destination2] as $file) {
                    if (file_exists($file)) {
                        unlink($file);
                    }
                }
            }
        });

        test('expired jar cookie is not sent during a download request', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'stale_download',
                value: 'old',
                expires: time() - 3600,
                domain: HttpBin::host(),
                path: '/',
            ));

            $destination = tempnam(sys_get_temp_dir(), 'hibla_dl_');

            try {
                await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->download(HttpBin::url('/cookies'), $destination)
                );

                $data = json_decode(file_get_contents($destination), true);
                expect($data['cookies'])->not->toHaveKey('stale_download');
            } finally {
                if (file_exists($destination)) {
                    unlink($destination);
                }
            }
        });

        test('multiple cookies in jar are all sent during a download request', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(name: 'dl_a', value: '1', domain: HttpBin::host(), path: '/'));
            $jar->setCookie(new Cookie(name: 'dl_b', value: '2', domain: HttpBin::host(), path: '/'));
            $jar->setCookie(new Cookie(name: 'dl_c', value: '3', domain: HttpBin::host(), path: '/'));

            $destination = tempnam(sys_get_temp_dir(), 'hibla_dl_');

            try {
                await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->download(HttpBin::url('/cookies'), $destination)
                );

                $data    = json_decode(file_get_contents($destination), true);
                $cookies = $data['cookies'];

                expect($cookies['dl_a'])->toBe('1');
                expect($cookies['dl_b'])->toBe('2');
                expect($cookies['dl_c'])->toBe('3');
            } finally {
                if (file_exists($destination)) {
                    unlink($destination);
                }
            }
        });
    });

    describe('Upload cookie handling', function () {

        test('jar cookie is sent during an upload request', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'upload_token',
                value: 'uploading',
                domain: HttpBin::host(),
                path: '/',
            ));

            $source = tempnam(sys_get_temp_dir(), 'hibla_ul_');
            file_put_contents($source, 'test upload content');

            try {
                $result = await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->upload(HttpBin::url('/put'), $source)
                );

                expect($result['status'])->toBe(200);
                $cookies = array_values($jar->getCookies(HttpBin::host(), '/'));
                $names   = array_map(fn(Cookie $c) => $c->getName(), $cookies);
                expect($names)->toContain('upload_token');
            } finally {
                if (file_exists($source)) {
                    unlink($source);
                }
            }
        });

        test('Set-Cookie header from an upload response is stored in the jar', function () {
            $jar    = new CookieJar();
            $source = tempnam(sys_get_temp_dir(), 'hibla_ul_');
            file_put_contents($source, 'test upload content');

            try {
                await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->withMethod('GET')
                        ->upload(
                            HttpBin::url('/response-headers?' . http_build_query([
                                'Set-Cookie' => 'upload_inbound=yes; Path=/; Domain=' . HttpBin::host(),
                            ])),
                            $source
                        )
                );

                $cookies = array_values($jar->getCookies(HttpBin::host(), '/'));
                $names   = array_map(fn(Cookie $c) => $c->getName(), $cookies);

                expect($names)->toContain('upload_inbound');
            } finally {
                if (file_exists($source)) {
                    unlink($source);
                }
            }
        });

        test('cookie stored from an upload response is replayed on the next regular request', function () {
            $jar    = new CookieJar();
            $source = tempnam(sys_get_temp_dir(), 'hibla_ul_');
            file_put_contents($source, 'test upload content');

            try {
                await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->withMethod('GET')
                        ->upload(
                            HttpBin::url('/response-headers?' . http_build_query([
                                'Set-Cookie' => 'upload_replay=yes; Path=/; Domain=' . HttpBin::host(),
                            ])),
                            $source
                        )
                );

                $response = await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->get(HttpBin::url('/cookies'))
                );

                expect($response->json('cookies.upload_replay'))->toBe('yes');
            } finally {
                if (file_exists($source)) {
                    unlink($source);
                }
            }
        });

        test('expired jar cookie is not sent during an upload request', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'stale_upload',
                value: 'old',
                expires: time() - 3600,
                domain: HttpBin::host(),
                path: '/',
            ));

            $source = tempnam(sys_get_temp_dir(), 'hibla_ul_');
            file_put_contents($source, 'test content');

            try {
                $result = await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->upload(HttpBin::url('/put'), $source)
                );

                expect($result['status'])->toBe(200);
                expect($jar->getCookies(HttpBin::host(), '/'))->toBeEmpty();
            } finally {
                if (file_exists($source)) {
                    unlink($source);
                }
            }
        });

        test('upload does not corrupt pre-existing cookies in the jar', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'persistent',
                value: 'survive',
                domain: HttpBin::host(),
                path: '/',
            ));

            $source = tempnam(sys_get_temp_dir(), 'hibla_ul_');
            file_put_contents($source, 'payload');

            try {
                await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->upload(HttpBin::url('/put'), $source)
                );

                $cookies = array_values($jar->getCookies(HttpBin::host(), '/'));
                $names   = array_map(fn(Cookie $c) => $c->getName(), $cookies);

                expect($names)->toContain('persistent');
            } finally {
                if (file_exists($source)) {
                    unlink($source);
                }
            }
        });
    });

    describe('SSE cookie handling', function () {

        test('jar cookie is sent during the SSE handshake', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'sse_token',
                value: 'handshake',
                domain: HttpBin::host(),
                path: '/',
            ));

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->sse(HttpBin::url('/cookies'))
                    ->connect()
            );

            $cookies = array_values($jar->getCookies(HttpBin::host(), '/'));
            $names   = array_map(fn(Cookie $c) => $c->getName(), $cookies);

            expect($names)->toContain('sse_token');
        });

        test('Set-Cookie header from the SSE handshake response is stored in the jar', function () {
            $jar = new CookieJar();

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->sse(HttpBin::url('/response-headers?' . http_build_query([
                        'Set-Cookie' => 'sse_inbound=yes; Path=/; Domain=' . HttpBin::host(),
                    ])))
                    ->connect()
            );

            $cookies = array_values($jar->getCookies(HttpBin::host(), '/'));
            $names   = array_map(fn(Cookie $c) => $c->getName(), $cookies);

            expect($names)->toContain('sse_inbound');
        });

        test('cookie stored from the SSE handshake is replayed on the next regular request', function () {
            $jar = new CookieJar();

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->sse(HttpBin::url('/response-headers?' . http_build_query([
                        'Set-Cookie' => 'sse_replay=yes; Path=/; Domain=' . HttpBin::host(),
                    ])))
                    ->connect()
            );

            $response = await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->get(HttpBin::url('/cookies'))
            );

            expect($response->json('cookies.sse_replay'))->toBe('yes');
        });

        test('expired jar cookie is not sent during the SSE handshake', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'stale_sse',
                value: 'old',
                expires: time() - 3600,
                domain: HttpBin::host(),
                path: '/',
            ));

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->sse(HttpBin::url('/response-headers'))
                    ->connect()
            );

            expect($jar->getCookies(HttpBin::host(), '/'))->toBeEmpty();
        });

        test('SSE does not corrupt pre-existing cookies in the jar', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'persistent_sse',
                value: 'survive',
                domain: HttpBin::host(),
                path: '/',
            ));

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->sse(HttpBin::url('/response-headers'))
                    ->connect()
            );

            $cookies = array_values($jar->getCookies(HttpBin::host(), '/'));
            $names   = array_map(fn(Cookie $c) => $c->getName(), $cookies);

            expect($names)->toContain('persistent_sse');
        });
    });
});