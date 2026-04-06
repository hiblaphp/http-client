<?php

declare(strict_types=1);

use Hibla\HttpClient\CookieJar;
use Hibla\HttpClient\HttpClient;
use Hibla\HttpClient\ValueObjects\Cookie;
use Tests\Fixtures\HttpBin;

use function Hibla\await;

describe('Cookie Security Test', function () {

    beforeEach(function () {
        HttpBin::skipIfUnreachable();
    });

    describe('Header injection prevention', function () {

        test('a cookie name containing CRLF is rejected before any network call is made', function () {
            expect(fn() => (new HttpClient())->withCookie("inject\r\nX-Injected: evil", 'value'))
                ->toThrow(\InvalidArgumentException::class, 'not permitted in an HTTP token');
        });

        test('a cookie name containing a lone CR is rejected before any network call is made', function () {
            expect(fn() => (new HttpClient())->withCookie("inject\rX-Injected: evil", 'value'))
                ->toThrow(\InvalidArgumentException::class, 'not permitted in an HTTP token');
        });

        test('a cookie name containing a lone LF is rejected before any network call is made', function () {
            expect(fn() => (new HttpClient())->withCookie("inject\nX-Injected: evil", 'value'))
                ->toThrow(\InvalidArgumentException::class, 'not permitted in an HTTP token');
        });

        test('a cookie value containing CRLF is rejected before any network call is made', function () {
            expect(fn() => (new HttpClient())->withCookie('name', "value\r\nX-Injected: evil"))
                ->toThrow(\InvalidArgumentException::class, 'cookie-octet set');
        });

        test('a cookie value containing a semicolon cannot break out of the cookie field', function () {
            expect(fn() => (new HttpClient())->withCookie('name', 'value; X-Injected: evil'))
                ->toThrow(\InvalidArgumentException::class, 'cookie-octet set');
        });

        test('a cookie name containing null bytes is rejected', function () {
            expect(fn() => (new HttpClient())->withCookie("name\x00evil", 'value'))
                ->toThrow(\InvalidArgumentException::class, 'not permitted in an HTTP token');
        });

        test('a cookie value containing null bytes is rejected', function () {
            expect(fn() => (new HttpClient())->withCookie('name', "value\x00evil"))
                ->toThrow(\InvalidArgumentException::class, 'cookie-octet set');
        });

        test('a streaming cookie name containing CRLF is rejected before any network call', function () {
            expect(
                fn() => (new HttpClient())
                    ->withCookie("bad\r\nname", 'value')
                    ->stream(HttpBin::url('/stream/1'))
            )->toThrow(\InvalidArgumentException::class);
        });

        test('a streaming cookie name containing a lone LF is rejected before any network call', function () {
            expect(
                fn() => (new HttpClient())
                    ->withCookie("bad\nname", 'value')
                    ->stream(HttpBin::url('/stream/1'))
            )->toThrow(\InvalidArgumentException::class);
        });

        test('a streaming cookie name containing a lone CR is rejected before any network call', function () {
            expect(
                fn() => (new HttpClient())
                    ->withCookie("bad\rname", 'value')
                    ->stream(HttpBin::url('/stream/1'))
            )->toThrow(\InvalidArgumentException::class);
        });

        test('a streaming cookie value containing CRLF is rejected before any network call', function () {
            expect(
                fn() => (new HttpClient())
                    ->withCookie('name', "bad\r\nvalue")
                    ->stream(HttpBin::url('/stream/1'))
            )->toThrow(\InvalidArgumentException::class);
        });

        test('a streaming cookie name containing null bytes is rejected before any network call', function () {
            expect(
                fn() => (new HttpClient())
                    ->withCookie("name\x00evil", 'value')
                    ->stream(HttpBin::url('/stream/1'))
            )->toThrow(\InvalidArgumentException::class);
        });

        test('a streaming cookie value containing null bytes is rejected before any network call', function () {
            expect(
                fn() => (new HttpClient())
                    ->withCookie('name', "value\x00evil")
                    ->stream(HttpBin::url('/stream/1'))
            )->toThrow(\InvalidArgumentException::class);
        });

        test('a streaming cookie value containing a semicolon is rejected before any network call', function () {
            expect(
                fn() => (new HttpClient())
                    ->withCookie('legit', 'val; injected=bad')
                    ->stream(HttpBin::url('/cookies'))
            )->toThrow(\InvalidArgumentException::class);
        });

        test('an SSE cookie name containing CRLF is rejected before any network call', function () {
            expect(
                fn() => (new HttpClient())
                    ->withCookie("bad\r\nname", 'value')
                    ->sse(HttpBin::url('/response-headers'))
                    ->connect()
            )->toThrow(\InvalidArgumentException::class);
        });

        test('an SSE cookie name containing a lone LF is rejected before any network call', function () {
            expect(
                fn() => (new HttpClient())
                    ->withCookie("bad\nname", 'value')
                    ->sse(HttpBin::url('/response-headers'))
                    ->connect()
            )->toThrow(\InvalidArgumentException::class);
        });

        test('an SSE cookie value containing CRLF is rejected before any network call', function () {
            expect(
                fn() => (new HttpClient())
                    ->withCookie('name', "bad\r\nvalue")
                    ->sse(HttpBin::url('/response-headers'))
                    ->connect()
            )->toThrow(\InvalidArgumentException::class);
        });

        test('an SSE cookie name containing null bytes is rejected before any network call', function () {
            expect(
                fn() => (new HttpClient())
                    ->withCookie("name\x00evil", 'value')
                    ->sse(HttpBin::url('/response-headers'))
                    ->connect()
            )->toThrow(\InvalidArgumentException::class);
        });

        test('an SSE cookie value containing null bytes is rejected before any network call', function () {
            expect(
                fn() => (new HttpClient())
                    ->withCookie('name', "value\x00evil")
                    ->sse(HttpBin::url('/response-headers'))
                    ->connect()
            )->toThrow(\InvalidArgumentException::class);
        });

        test('a download cookie name containing CRLF is rejected before any network call', function () {
            $destination = tempnam(sys_get_temp_dir(), 'hibla_dl_');

            try {
                expect(
                    fn() => (new HttpClient())
                        ->withCookie("bad\r\nname", 'value')
                        ->download(HttpBin::url('/get'), $destination)
                )->toThrow(\InvalidArgumentException::class);
            } finally {
                if (file_exists($destination)) {
                    unlink($destination);
                }
            }
        });

        test('a download cookie value containing CRLF is rejected before any network call', function () {
            $destination = tempnam(sys_get_temp_dir(), 'hibla_dl_');

            try {
                expect(
                    fn() => (new HttpClient())
                        ->withCookie('name', "bad\r\nvalue")
                        ->download(HttpBin::url('/get'), $destination)
                )->toThrow(\InvalidArgumentException::class);
            } finally {
                if (file_exists($destination)) {
                    unlink($destination);
                }
            }
        });

        test('an upload cookie name containing CRLF is rejected before any network call', function () {
            $source = tempnam(sys_get_temp_dir(), 'hibla_ul_');
            file_put_contents($source, 'payload');

            try {
                expect(
                    fn() => (new HttpClient())
                        ->withCookie("bad\r\nname", 'value')
                        ->upload(HttpBin::url('/put'), $source)
                )->toThrow(\InvalidArgumentException::class);
            } finally {
                if (file_exists($source)) {
                    unlink($source);
                }
            }
        });

        test('an upload cookie value containing CRLF is rejected before any network call', function () {
            $source = tempnam(sys_get_temp_dir(), 'hibla_ul_');
            file_put_contents($source, 'payload');

            try {
                expect(
                    fn() => (new HttpClient())
                        ->withCookie('name', "bad\r\nvalue")
                        ->upload(HttpBin::url('/put'), $source)
                )->toThrow(\InvalidArgumentException::class);
            } finally {
                if (file_exists($source)) {
                    unlink($source);
                }
            }
        });
    });

    describe('Cross-domain isolation', function () {

        test('a cookie scoped to one domain is not sent to a different domain', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'secret',
                value: 'sensitive',
                domain: HttpBin::host(),
                path: '/',
            ));

            $header = $jar->getCookieHeader('evil.com', '/');

            expect($header)->toBe('');
            expect($header)->not->toContain('secret=sensitive');
        });

        test('a cookie wildcard-scoped does not leak to a completely different domain', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'wide',
                value: 'yes',
                domain: '.' . HttpBin::host(),
                path: '/',
            ));

            expect($jar->getCookieHeader(HttpBin::host(), '/'))->toContain('wide=yes');
            expect($jar->getCookieHeader('evil.com', '/'))->not->toContain('wide=yes');
        });

        test('a cookie sent to the server is not echoed back to a different domain', function () {
            $jar = new CookieJar();

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->redirects(true)
                    ->get(HttpBin::url('/cookies/set?private=yes'))
            );

            $header = $jar->getCookieHeader('attacker.com', '/');

            expect($header)->toBe('');
            expect($header)->not->toContain('private=yes');
        });

        test('a cookie stored from a streaming response is not sent to a different domain', function () {
            $jar = new CookieJar();

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->stream(HttpBin::url('/response-headers?' . http_build_query([
                        'Set-Cookie' => 'stream_secret=yes; Path=/; Domain=' . HttpBin::host(),
                    ])))
            );

            $cookies = array_values($jar->getCookies(HttpBin::host(), '/'));
            $names   = array_map(fn(Cookie $c) => $c->getName(), $cookies);
            expect($names)->toContain('stream_secret');

            $leaked = array_values($jar->getCookies('evil.com', '/'));
            expect($leaked)->toBeEmpty();
        });

        test('a streaming jar cookie scoped to one domain is not sent to a sibling domain', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'scoped_stream',
                value: 'yes',
                domain: HttpBin::host(),
                path: '/',
            ));

            $leaked = array_values($jar->getCookies('otherdomain.org', '/'));
            expect($leaked)->toBeEmpty();
        });

        test('a cookie stored from an SSE handshake is not accessible to a different domain', function () {
            $jar = new CookieJar();

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->sse(HttpBin::url('/response-headers?' . http_build_query([
                        'Set-Cookie' => 'sse_secret=yes; Path=/; Domain=' . HttpBin::host(),
                    ])))
                    ->connect()
            );

            $cookies = array_values($jar->getCookies(HttpBin::host(), '/'));
            $names   = array_map(fn(Cookie $c) => $c->getName(), $cookies);
            expect($names)->toContain('sse_secret');

            $leaked = array_values($jar->getCookies('attacker.com', '/'));
            expect($leaked)->toBeEmpty();
        });

        test('an SSE jar cookie scoped to one domain is not sent to a sibling domain', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'scoped_sse',
                value: 'yes',
                domain: HttpBin::host(),
                path: '/',
            ));

            $leaked = array_values($jar->getCookies('otherdomain.org', '/'));
            expect($leaked)->toBeEmpty();
        });

        test('a cookie stored from a download response is not accessible to a different domain', function () {
            $jar         = new CookieJar();
            $destination = tempnam(sys_get_temp_dir(), 'hibla_dl_');

            try {
                await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->download(
                            HttpBin::url('/response-headers?' . http_build_query([
                                'Set-Cookie' => 'dl_secret=yes; Path=/; Domain=' . HttpBin::host(),
                            ])),
                            $destination
                        )
                );

                $cookies = array_values($jar->getCookies(HttpBin::host(), '/'));
                $names   = array_map(fn(Cookie $c) => $c->getName(), $cookies);
                expect($names)->toContain('dl_secret');

                $leaked = array_values($jar->getCookies('evil.com', '/'));
                expect($leaked)->toBeEmpty();
            } finally {
                if (file_exists($destination)) {
                    unlink($destination);
                }
            }
        });

        test('an upload jar cookie scoped to one domain is not sent to a different domain', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'ul_scoped',
                value: 'yes',
                domain: HttpBin::host(),
                path: '/',
            ));

            $leaked = array_values($jar->getCookies('evil.com', '/'));
            expect($leaked)->toBeEmpty();
        });
    });

    describe('Secure flag enforcement', function () {

        test('a Secure cookie is withheld over HTTP even if the domain matches', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'topsecret',
                value: 'classified',
                domain: HttpBin::host(),
                path: '/',
                secure: true,
            ));

            $header = $jar->getCookieHeader(HttpBin::host(), '/', isSecure: false);

            expect($header)->toBe('');
            expect($header)->not->toContain('topsecret=classified');
        });

        test('a Secure cookie in the jar is not sent during a streaming request over a non-secure scheme', function () {
            $jar = new CookieJar();
            $jar->setCookie(Cookie::fromSetCookieHeader(
                'secure_stream=yes; Secure; Path=/',
                HttpBin::host()
            ));

            $cookies = array_values($jar->getCookies(HttpBin::host(), '/', false));
            expect($cookies)->toBeEmpty();
        });

        test('a non-Secure cookie in the jar is sent during a streaming request', function () {
            $jar = new CookieJar();
            $jar->setCookie(Cookie::fromSetCookieHeader(
                'plain_stream=yes; Path=/',
                HttpBin::host()
            ));

            $cookies = array_values($jar->getCookies(HttpBin::host(), '/', false));
            $names   = array_map(fn(Cookie $c) => $c->getName(), $cookies);
            expect($names)->toContain('plain_stream');
        });

        test('a Secure cookie in the jar is not sent during an SSE handshake over a non-secure scheme', function () {
            $jar = new CookieJar();
            $jar->setCookie(Cookie::fromSetCookieHeader(
                'secure_sse=yes; Secure; Path=/',
                HttpBin::host()
            ));

            $cookies = array_values($jar->getCookies(HttpBin::host(), '/', false));
            expect($cookies)->toBeEmpty();
        });
    });

    describe('Expired cookie enforcement', function () {

        test('an expired cookie is not sent even if the domain and path match exactly', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'stale',
                value: 'leaked',
                expires: time() - 1,
                domain: HttpBin::host(),
                path: '/',
            ));

            $header = $jar->getCookieHeader(HttpBin::host(), '/');

            expect($header)->toBe('');
            expect($header)->not->toContain('stale=leaked');
        });

        test('an already-expired cookie is not sent during a streaming request', function () {
            $jar = new CookieJar();
            $jar->setCookie(Cookie::fromSetCookieHeader(
                'dead_stream=yes; Expires=' . gmdate('D, d M Y H:i:s T', time() - 3600) . '; Path=/',
                HttpBin::host()
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
            expect($data['cookies'])->not->toHaveKey('dead_stream');
        });

        test('a cookie with max-age=0 from a streaming response is treated as immediately expired', function () {
            $jar = new CookieJar();
            $jar->setCookie(Cookie::fromSetCookieHeader(
                'gone_stream=yes; Max-Age=0; Path=/',
                HttpBin::host()
            ));

            $cookies = array_values($jar->getCookies(HttpBin::host(), '/'));
            expect($cookies)->toBeEmpty();
        });

        test('an already-expired cookie is not sent during an SSE handshake', function () {
            $jar = new CookieJar();
            $jar->setCookie(Cookie::fromSetCookieHeader(
                'dead_sse=yes; Expires=' . gmdate('D, d M Y H:i:s T', time() - 3600) . '; Path=/',
                HttpBin::host()
            ));

            $cookies = array_values($jar->getCookies(HttpBin::host(), '/'));
            expect($cookies)->toBeEmpty();
        });

        test('a cookie with a negative max-age from an SSE handshake is treated as immediately expired', function () {
            $jar = new CookieJar();
            $jar->setCookie(Cookie::fromSetCookieHeader(
                'gone_sse=yes; Max-Age=-1; Path=/',
                HttpBin::host()
            ));

            $cookies = array_values($jar->getCookies(HttpBin::host(), '/'));
            expect($cookies)->toBeEmpty();
        });

        test('an already-expired cookie is not sent during a download request', function () {
            $jar = new CookieJar();
            $jar->setCookie(Cookie::fromSetCookieHeader(
                'dead_dl=yes; Expires=' . gmdate('D, d M Y H:i:s T', time() - 3600) . '; Path=/',
                HttpBin::host()
            ));

            $destination = tempnam(sys_get_temp_dir(), 'hibla_dl_');

            try {
                await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->download(HttpBin::url('/cookies'), $destination)
                );

                $data = json_decode(file_get_contents($destination), true);
                expect($data['cookies'])->not->toHaveKey('dead_dl');
            } finally {
                if (file_exists($destination)) {
                    unlink($destination);
                }
            }
        });

        test('an already-expired cookie is not sent during an upload request', function () {
            $jar = new CookieJar();
            $jar->setCookie(Cookie::fromSetCookieHeader(
                'dead_ul=yes; Expires=' . gmdate('D, d M Y H:i:s T', time() - 3600) . '; Path=/',
                HttpBin::host()
            ));

            $source = tempnam(sys_get_temp_dir(), 'hibla_ul_');
            file_put_contents($source, 'payload');

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
    });

    describe('Response body is not parsed for Set-Cookie headers', function () {

        test('a Set-Cookie header embedded in a streaming response body is not stored in the jar', function () {
            $jar    = new CookieJar();
            $chunks = '';

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->stream(HttpBin::url('/stream/1'), function (string $chunk) use (&$chunks): void {
                        $chunks .= $chunk;
                    })
            );

            $allCookies = $jar->getAllCookies();
            expect($allCookies)->toBeEmpty();
        });
    });
});