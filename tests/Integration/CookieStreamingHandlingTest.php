<?php

declare(strict_types=1);

use Hibla\HttpClient\CookieJar;
use Hibla\HttpClient\HttpClient;
use Hibla\HttpClient\ValueObjects\Cookie;

use function Hibla\await;

const HTTPBIN = 'https://httpbin.org';

describe('Cookie Streaming Handling Integration Test', function () {

    // -------------------------------------------------------------------------
    // Streaming
    // -------------------------------------------------------------------------
    // Uses HttpClient::stream() which owns its own CURLOPT_HEADERFUNCTION and
    // calls parseRawHeaders() internally, so header keys are title-cased.
    // -------------------------------------------------------------------------

    describe('Streaming cookie handling', function () {

        test('jar cookie is sent during a streaming request', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'stream_token',
                value: 'streamed',
                domain: 'httpbin.org',
                path: '/',
            ));

            $chunks = '';

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->stream(HTTPBIN . '/cookies', function (string $chunk) use (&$chunks): void {
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
                    ->stream(HTTPBIN . '/response-headers?' . http_build_query([
                        'Set-Cookie' => 'stream_inbound=yes; Path=/; Domain=httpbin.org',
                    ]))
            );

            $cookies = array_values($jar->getCookies('httpbin.org', '/'));
            $names   = array_map(fn (Cookie $c) => $c->getName(), $cookies);

            expect($names)->toContain('stream_inbound');
        });

        test('cookie stored from a streaming response is replayed on the next regular request', function () {
            $jar = new CookieJar();

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->stream(HTTPBIN . '/response-headers?' . http_build_query([
                        'Set-Cookie' => 'stream_replay=yes; Path=/; Domain=httpbin.org',
                    ]))
            );

            $response = await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->get(HTTPBIN . '/cookies')
            );

            expect($response->json('cookies.stream_replay'))->toBe('yes');
        });

        test('cookie stored from a streaming response is replayed on the next streaming request', function () {
            $jar = new CookieJar();

            // First stream — server sets a cookie
            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->stream(HTTPBIN . '/response-headers?' . http_build_query([
                        'Set-Cookie' => 'stream_chain=chained; Path=/; Domain=httpbin.org',
                    ]))
            );

            // Second stream — jar should replay the stored cookie
            $chunks = '';
            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->stream(HTTPBIN . '/cookies', function (string $chunk) use (&$chunks): void {
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
                domain: 'httpbin.org',
                path: '/',
            ));

            $chunks = '';

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->withCookie('manual_stream', 'manual')
                    ->stream(HTTPBIN . '/cookies', function (string $chunk) use (&$chunks): void {
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
                domain: 'httpbin.org',
                path: '/',
            ));

            $chunks = '';

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->stream(HTTPBIN . '/cookies', function (string $chunk) use (&$chunks): void {
                        $chunks .= $chunk;
                    })
            );

            $data = json_decode($chunks, true);
            expect($data['cookies'])->not->toHaveKey('stale_stream');
        });
    });

    // -------------------------------------------------------------------------
    // Download
    // -------------------------------------------------------------------------
    // Uses StreamingHandler::downloadFile(). CurlRequest normalises captured
    // header names to lowercase, so Set-Cookie arrives as 'set-cookie' in
    // the completion callback.
    // -------------------------------------------------------------------------

    describe('Download cookie handling', function () {

        test('jar cookie is sent during a download request', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'download_token',
                value: 'downloading',
                domain: 'httpbin.org',
                path: '/',
            ));

            $destination = tempnam(sys_get_temp_dir(), 'hibla_dl_');

            try {
                await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->download(HTTPBIN . '/cookies', $destination)
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
                            HTTPBIN . '/response-headers?' . http_build_query([
                                'Set-Cookie' => 'download_inbound=yes; Path=/; Domain=httpbin.org',
                            ]),
                            $destination
                        )
                );

                $cookies = array_values($jar->getCookies('httpbin.org', '/'));
                $names   = array_map(fn (Cookie $c) => $c->getName(), $cookies);

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
                            HTTPBIN . '/response-headers?' . http_build_query([
                                'Set-Cookie' => 'download_replay=yes; Path=/; Domain=httpbin.org',
                            ]),
                            $destination
                        )
                );

                $response = await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->get(HTTPBIN . '/cookies')
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
                // First download — server sets a cookie
                await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->download(
                            HTTPBIN . '/response-headers?' . http_build_query([
                                'Set-Cookie' => 'dl_chain=chained; Path=/; Domain=httpbin.org',
                            ]),
                            $destination1
                        )
                );

                // Second download — jar should replay the stored cookie
                await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->download(HTTPBIN . '/cookies', $destination2)
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
                domain: 'httpbin.org',
                path: '/',
            ));

            $destination = tempnam(sys_get_temp_dir(), 'hibla_dl_');

            try {
                await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->download(HTTPBIN . '/cookies', $destination)
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
            $jar->setCookie(new Cookie(name: 'dl_a', value: '1', domain: 'httpbin.org', path: '/'));
            $jar->setCookie(new Cookie(name: 'dl_b', value: '2', domain: 'httpbin.org', path: '/'));
            $jar->setCookie(new Cookie(name: 'dl_c', value: '3', domain: 'httpbin.org', path: '/'));

            $destination = tempnam(sys_get_temp_dir(), 'hibla_dl_');

            try {
                await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->download(HTTPBIN . '/cookies', $destination)
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

    // -------------------------------------------------------------------------
    // Upload
    // -------------------------------------------------------------------------
    // Uses StreamingHandler::uploadFile(). Same CurlRequest header normalisation
    // applies — 'set-cookie' lowercase in the completion callback.
    //
    // Note: httpbin's /put and /post endpoints do not return Set-Cookie headers,
    // so inbound cookie tests use /response-headers to synthesise a Set-Cookie
    // response on GET, which upload() honours via its explicit method override.
    // -------------------------------------------------------------------------

    describe('Upload cookie handling', function () {

        test('jar cookie is sent during an upload request', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'upload_token',
                value: 'uploading',
                domain: 'httpbin.org',
                path: '/',
            ));

            $source = tempnam(sys_get_temp_dir(), 'hibla_ul_');
            file_put_contents($source, 'test upload content');

            try {
                // httpbin /put echoes request headers in the body. upload() does not
                // expose the body, but status 200 confirms the server accepted the
                // request. Outbound cookie correctness is enforced by the same
                // CurlOptionsBuilder::mergeCookieHeader() path used by all other methods.
                $result = await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->upload(HTTPBIN . '/put', $source)
                );

                expect($result['status'])->toBe(200);
                // The jar must still contain our cookie after the upload completes.
                $cookies = array_values($jar->getCookies('httpbin.org', '/'));
                $names   = array_map(fn (Cookie $c) => $c->getName(), $cookies);
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
                            HTTPBIN . '/response-headers?' . http_build_query([
                                'Set-Cookie' => 'upload_inbound=yes; Path=/; Domain=httpbin.org',
                            ]),
                            $source
                        )
                );

                $cookies = array_values($jar->getCookies('httpbin.org', '/'));
                $names   = array_map(fn (Cookie $c) => $c->getName(), $cookies);

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
                            HTTPBIN . '/response-headers?' . http_build_query([
                                'Set-Cookie' => 'upload_replay=yes; Path=/; Domain=httpbin.org',
                            ]),
                            $source
                        )
                );

                $response = await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->get(HTTPBIN . '/cookies')
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
                domain: 'httpbin.org',
                path: '/',
            ));

            $source = tempnam(sys_get_temp_dir(), 'hibla_ul_');
            file_put_contents($source, 'test content');

            try {
                $result = await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->upload(HTTPBIN . '/put', $source)
                );

                expect($result['status'])->toBe(200);
                // Expired cookie must not have been added back to the jar by the response.
                expect($jar->getCookies('httpbin.org', '/'))->toBeEmpty();
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
                domain: 'httpbin.org',
                path: '/',
            ));

            $source = tempnam(sys_get_temp_dir(), 'hibla_ul_');
            file_put_contents($source, 'payload');

            try {
                await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->upload(HTTPBIN . '/put', $source)
                );

                $cookies = array_values($jar->getCookies('httpbin.org', '/'));
                $names   = array_map(fn (Cookie $c) => $c->getName(), $cookies);

                expect($names)->toContain('persistent');
            } finally {
                if (file_exists($source)) {
                    unlink($source);
                }
            }
        });
    });

    // -------------------------------------------------------------------------
    // SSE
    // -------------------------------------------------------------------------
    // The SSE handshake is a standard HTTP request whose response headers are
    // processed by a dedicated CURLOPT_HEADERFUNCTION inside createSSEConnection.
    // Cookie storage fires before the promise resolves, so by the time await()
    // returns the jar is already populated.
    //
    // httpbin does not expose a native SSE endpoint. These tests connect to
    // /response-headers (200 JSON) to exercise the handshake cookie path.
    // The SSE handler accepts any 2xx response at the header level; the body
    // is parsed for events but silently ignored when no valid SSE frames arrive.
    // -------------------------------------------------------------------------

    describe('SSE cookie handling', function () {

        test('jar cookie is sent during the SSE handshake', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'sse_token',
                value: 'handshake',
                domain: 'httpbin.org',
                path: '/',
            ));

            // /cookies echoes back every cookie the client sends.
            // The SSE handler connects and resolves the promise on the 200 response.
            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->sse(HTTPBIN . '/cookies')
                    ->connect()
            );

            // The jar must still hold our cookie after the SSE lifecycle completes.
            $cookies = array_values($jar->getCookies('httpbin.org', '/'));
            $names   = array_map(fn (Cookie $c) => $c->getName(), $cookies);

            expect($names)->toContain('sse_token');
        });

        test('Set-Cookie header from the SSE handshake response is stored in the jar', function () {
            $jar = new CookieJar();

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->sse(HTTPBIN . '/response-headers?' . http_build_query([
                        'Set-Cookie' => 'sse_inbound=yes; Path=/; Domain=httpbin.org',
                    ]))
                    ->connect()
            );

            $cookies = array_values($jar->getCookies('httpbin.org', '/'));
            $names   = array_map(fn (Cookie $c) => $c->getName(), $cookies);

            expect($names)->toContain('sse_inbound');
        });

        test('cookie stored from the SSE handshake is replayed on the next regular request', function () {
            $jar = new CookieJar();

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->sse(HTTPBIN . '/response-headers?' . http_build_query([
                        'Set-Cookie' => 'sse_replay=yes; Path=/; Domain=httpbin.org',
                    ]))
                    ->connect()
            );

            $response = await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->get(HTTPBIN . '/cookies')
            );

            expect($response->json('cookies.sse_replay'))->toBe('yes');
        });

        test('expired jar cookie is not sent during the SSE handshake', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'stale_sse',
                value: 'old',
                expires: time() - 3600,
                domain: 'httpbin.org',
                path: '/',
            ));

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->sse(HTTPBIN . '/response-headers')
                    ->connect()
            );

            // Expired cookie must not have been re-introduced into the jar.
            expect($jar->getCookies('httpbin.org', '/'))->toBeEmpty();
        });

        test('SSE does not corrupt pre-existing cookies in the jar', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'persistent_sse',
                value: 'survive',
                domain: 'httpbin.org',
                path: '/',
            ));

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->sse(HTTPBIN . '/response-headers')
                    ->connect()
            );

            $cookies = array_values($jar->getCookies('httpbin.org', '/'));
            $names   = array_map(fn (Cookie $c) => $c->getName(), $cookies);

            expect($names)->toContain('persistent_sse');
        });
    });
})->skipOnCI();