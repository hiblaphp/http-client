<?php

declare(strict_types=1);

use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\ValueObjects\ProxyConfig;
use Tests\Fixtures\HttpBin;
use Tests\Fixtures\ProxySetup;

describe('Proxy', function () {
    describe('ProxyConfig value object', function () {

        it('builds an HTTP proxy URL without credentials', function () {
            $config = ProxyConfig::http('proxy.local', 8080);

            expect($config->getProxyUrl())->toBe('http://proxy.local:8080')
                ->and($config->type)->toBe('http')
                ->and($config->username)->toBeNull()
                ->and($config->password)->toBeNull()
            ;
        });

        it('builds an HTTP proxy URL with credentials', function () {
            $config = ProxyConfig::http('proxy.local', 8080, 'user', 'secret');

            expect($config->getProxyUrl())->toBe('http://user:secret@proxy.local:8080');
        });

        it('builds a SOCKS4 proxy URL — no password field', function () {
            $config = ProxyConfig::socks4('10.0.0.1', 1080, 'user');

            expect($config->getProxyUrl())->toBe('socks4://user@10.0.0.1:1080')
                ->and($config->password)->toBeNull()
            ;
        });

        it('builds a SOCKS5 proxy URL with credentials', function () {
            $config = ProxyConfig::socks5('10.0.0.1', 1080, 'user', 'pass');

            expect($config->getProxyUrl())->toBe('socks5://user:pass@10.0.0.1:1080');
        });

        it('builds a SOCKS5 proxy URL without credentials', function () {
            $config = ProxyConfig::socks5('10.0.0.1', 1080);

            expect($config->getProxyUrl())->toBe('socks5://10.0.0.1:1080')
                ->and($config->username)->toBeNull()
                ->and($config->password)->toBeNull()
            ;
        });

        it('returns correct cURL proxy type constants', function () {
            expect(ProxyConfig::http('h', 1)->getCurlProxyType())->toBe(CURLPROXY_HTTP)
                ->and(ProxyConfig::socks4('h', 1)->getCurlProxyType())->toBe(CURLPROXY_SOCKS4A)
                ->and(ProxyConfig::socks5('h', 1)->getCurlProxyType())->toBe(CURLPROXY_SOCKS5_HOSTNAME)
            ;
        });
    });

    describe('HttpClient proxy chain immutability', function () {

        it('withProxy() returns a new instance', function () {
            $a = ProxySetup::client();
            $b = $a->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort());

            expect($b)->not->toBe($a);
        });

        it('withoutProxy() strips a previously configured proxy', function () {
            $stripped = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->withoutProxy()
            ;

            expect(ProxySetup::readPrivate($stripped, 'proxyConfig'))->toBeNull();
        });

        it('withProxyConfig() stores a hand-crafted ProxyConfig', function () {
            $config = new ProxyConfig(
                host: 'custom.proxy',
                port: 9090,
                username: 'admin',
                password: 'pw',
                type: 'socks5',
            );

            expect(ProxySetup::readPrivate(
                ProxySetup::client()->withProxyConfig($config),
                'proxyConfig'
            ))->toBe($config);
        });

        it('does not mutate the base instance when branching', function () {
            $base = ProxySetup::client()->withToken('tok')->asJson();
            $proxied = $base->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort());
            $unproxied = $base->withoutProxy();

            expect(ProxySetup::readPrivate($proxied, 'proxyConfig'))->not->toBeNull()
                ->and(ProxySetup::readPrivate($unproxied, 'proxyConfig'))->toBeNull()
                ->and(ProxySetup::readPrivate($base, 'proxyConfig'))->toBeNull()
            ;
        });

        it('the last withProxy() call wins', function () {
            $config = ProxySetup::readPrivate(
                ProxySetup::client()
                    ->withProxy('first.proxy', 8080)
                    ->withProxy('second.proxy', 9090),
                'proxyConfig'
            );

            expect($config->host)->toBe('second.proxy')
                ->and($config->port)->toBe(9090)
            ;
        });

        it('switching proxy type mid-chain stores the latest type', function () {
            $config = ProxySetup::readPrivate(
                ProxySetup::client()
                    ->withProxy('http.proxy', 8080)
                    ->withSocks5Proxy('socks.proxy', 1080),
                'proxyConfig'
            );

            expect($config->type)->toBe('socks5')
                ->and($config->host)->toBe('socks.proxy')
            ;
        });
    });

    describe('HTTP proxy — Squid', function () {

        beforeEach(function () {
            HttpBin::skipIfUnreachable();
            ProxySetup::skipIfUnreachable(ProxySetup::httpHost(), ProxySetup::httpPort());
        });

        it('routes GET through the HTTP proxy', function () {
            $response = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->get(HttpBin::proxyUrl('/get'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and($response->json('url'))->not->toBeEmpty()
            ;
        });

        it('forwards custom headers through the proxy', function () {
            $response = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->withHeader('X-Proxy-Test', 'squid')
                ->get(HttpBin::proxyUrl('/headers'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and($response->json('headers.X-Proxy-Test.0'))->toBe('squid')
            ;
        });

        it('POSTs JSON through the HTTP proxy', function () {
            $response = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->post(HttpBin::proxyUrl('/post'), ['proxy' => 'http', 'framework' => 'pest'])
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and($response->json('json.proxy'))->toBe('http')
                ->and($response->json('json.framework'))->toBe('pest')
            ;
        });

        it('PUTs JSON through the HTTP proxy', function () {
            $response = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->put(HttpBin::proxyUrl('/put'), ['action' => 'update'])
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and($response->json('json.action'))->toBe('update')
            ;
        });

        it('DELETEs through the HTTP proxy', function () {
            $response = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->delete(HttpBin::proxyUrl('/delete'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue();
        });

        it('withoutProxy() branch bypasses the proxy on the same chain', function () {
            $response = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->withoutProxy()
                ->get(HttpBin::url('/get'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue();
        });

        it('follows redirects through the proxy', function () {
            $response = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->redirects(true, 5)
                ->get(HttpBin::proxyUrl('/absolute-redirect/2'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue();
        });

        it('does not follow redirects when disabled', function () {
            $response = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->redirects(false)
                ->get(HttpBin::proxyUrl('/absolute-redirect/1'))
                ->wait()
            ;

            expect($response->status())->toBe(302);
        });

        it('sends a large JSON payload', function () {
            $response = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->post(HttpBin::proxyUrl('/post'), ['data' => str_repeat('x', 10_000)])
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and(strlen($response->json('json.data')))->toBe(10_000)
            ;
        });

        it('preserves query parameters', function () {
            $response = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->get(HttpBin::proxyUrl('/get') . '?foo=bar&baz=qux')
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and($response->json('args.foo.0'))->toBe('bar')
                ->and($response->json('args.baz.0'))->toBe('qux')
            ;
        });

        it('handles 204 No Content', function () {
            $response = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->get(HttpBin::proxyUrl('/status/204'))
                ->wait()
            ;

            expect($response->status())->toBe(204)
                ->and($response->body())->toBe('')
            ;
        });
    });

    describe('SOCKS5 proxy', function () {

        beforeEach(function () {
            HttpBin::skipIfUnreachable();
            ProxySetup::skipIfUnreachable(ProxySetup::socks5Host(), ProxySetup::socks5Port());
        });

        it('routes GET through the SOCKS5 proxy', function () {
            $response = ProxySetup::client()
                ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port(), ProxySetup::socks5User(), ProxySetup::socks5Pass())
                ->get(HttpBin::socksProxyUrl('/get'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and($response->json('url'))->not->toBeEmpty()
            ;
        });

        it('POSTs JSON through SOCKS5', function () {
            $response = ProxySetup::client()
                ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port(), ProxySetup::socks5User(), ProxySetup::socks5Pass())
                ->post(HttpBin::socksProxyUrl('/post'), ['via' => 'socks5'])
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and($response->json('json.via'))->toBe('socks5')
            ;
        });

        it('forwards custom headers through SOCKS5', function () {
            $response = ProxySetup::client()
                ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port(), ProxySetup::socks5User(), ProxySetup::socks5Pass())
                ->withHeader('X-Proxy-Test', 'socks5')
                ->get(HttpBin::socksProxyUrl('/headers'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and($response->json('headers.X-Proxy-Test.0'))->toBe('socks5')
            ;
        });

        it('authenticates with SOCKS5 when credentials are set', function () {
            $user = ProxySetup::socks5User();
            $pass = ProxySetup::socks5Pass();

            if ($user === null) {
                test()->markTestSkipped('SOCKS5_USER not set — skipping auth test');
            }

            $response = ProxySetup::client()
                ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port(), $user, $pass)
                ->get(HttpBin::socksProxyUrl('/get'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue();
        });

        it('follows redirects through SOCKS5', function () {
            $response = ProxySetup::client()
                ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port(), ProxySetup::socks5User(), ProxySetup::socks5Pass())
                ->redirects(true, 5)
                ->get(HttpBin::socksProxyUrl('/absolute-redirect/2'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue();
        });

        it('sends a large JSON payload through SOCKS5', function () {
            $response = ProxySetup::client()
                ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port(), ProxySetup::socks5User(), ProxySetup::socks5Pass())
                ->post(HttpBin::socksProxyUrl('/post'), ['data' => str_repeat('y', 10_000)])
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and(strlen($response->json('json.data')))->toBe(10_000)
            ;
        });

        it('preserves query parameters through SOCKS5', function () {
            $response = ProxySetup::client()
                ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port(), ProxySetup::socks5User(), ProxySetup::socks5Pass())
                ->get(HttpBin::socksProxyUrl('/get') . '?via=socks5&test=1')
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and($response->json('args.via.0'))->toBe('socks5')
                ->and($response->json('args.test.0'))->toBe('1')
            ;
        });

        it('handles 204 No Content through SOCKS5', function () {
            $response = ProxySetup::client()
                ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port(), ProxySetup::socks5User(), ProxySetup::socks5Pass())
                ->get(HttpBin::socksProxyUrl('/status/204'))
                ->wait()
            ;

            expect($response->status())->toBe(204)
                ->and($response->body())->toBe('')
            ;
        });
    });

    describe('SOCKS4 proxy', function () {

        beforeEach(function () {
            HttpBin::skipIfUnreachable();
            ProxySetup::skipIfUnreachable(ProxySetup::socks4Host(), ProxySetup::socks4Port());
        });

        it('routes GET through the SOCKS4 proxy', function () {
            $response = ProxySetup::client()
                ->withSocks4Proxy(ProxySetup::socks4Host(), ProxySetup::socks4Port())
                ->get(HttpBin::socksProxyUrl('/get'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and($response->json('url'))->not->toBeEmpty()
            ;
        });

        it('POSTs JSON through SOCKS4', function () {
            $response = ProxySetup::client()
                ->withSocks4Proxy(ProxySetup::socks4Host(), ProxySetup::socks4Port())
                ->post(HttpBin::socksProxyUrl('/post'), ['via' => 'socks4'])
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and($response->json('json.via'))->toBe('socks4')
            ;
        });

        it('follows redirects through SOCKS4', function () {
            $response = ProxySetup::client()
                ->withSocks4Proxy(ProxySetup::socks4Host(), ProxySetup::socks4Port())
                ->redirects(true, 5)
                ->get(HttpBin::socksProxyUrl('/absolute-redirect/2'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue();
        });

        it('sends a large JSON payload through SOCKS4', function () {
            $response = ProxySetup::client()
                ->withSocks4Proxy(ProxySetup::socks4Host(), ProxySetup::socks4Port())
                ->post(HttpBin::socksProxyUrl('/post'), ['data' => str_repeat('z', 10_000)])
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and(strlen($response->json('json.data')))->toBe(10_000)
            ;
        });

        it('preserves query parameters through SOCKS4', function () {
            $response = ProxySetup::client()
                ->withSocks4Proxy(ProxySetup::socks4Host(), ProxySetup::socks4Port())
                ->get(HttpBin::socksProxyUrl('/get') . '?via=socks4&test=1')
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and($response->json('args.via.0'))->toBe('socks4')
                ->and($response->json('args.test.0'))->toBe('1')
            ;
        });
    });

    describe('proxy switching across types', function () {

        beforeEach(function () {
            HttpBin::skipIfUnreachable();
            ProxySetup::skipIfUnreachable(ProxySetup::httpHost(), ProxySetup::httpPort());
            ProxySetup::skipIfUnreachable(ProxySetup::socks5Host(), ProxySetup::socks5Port());
        });

        it('can branch from HTTP proxy to SOCKS5 proxy off the same base client', function () {
            $base = ProxySetup::client()->withToken('test-token');

            $viaHttp = $base
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->get(HttpBin::proxyUrl('/get'))
                ->wait()
            ;

            $viaSocks5 = $base
                ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port(), ProxySetup::socks5User(), ProxySetup::socks5Pass())
                ->get(HttpBin::socksProxyUrl('/get'))
                ->wait()
            ;

            expect($viaHttp->successful())->toBeTrue()
                ->and($viaSocks5->successful())->toBeTrue()
            ;
        });

        it('base client is unaffected after branching', function () {
            $base = ProxySetup::client();

            $base->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->get(HttpBin::proxyUrl('/get'))
                ->wait()
            ;

            expect(ProxySetup::readPrivate($base, 'proxyConfig'))->toBeNull();
        });
    });

    describe('graceful failure', function () {

        beforeEach(function () {
            HttpBin::skipIfUnreachable();
        });

        it('throws on unreachable HTTP proxy', function () {
            expect(
                fn () => ProxySetup::client(timeout: 3)
                    ->withProxy('127.0.0.1', 19999)
                    ->get(HttpBin::url('/get'))
                    ->wait()
            )->toThrow(NetworkException::class);
        });

        it('throws on unreachable SOCKS5 proxy', function () {
            expect(
                fn () => ProxySetup::client(timeout: 3)
                    ->withSocks5Proxy('127.0.0.1', 19998)
                    ->get(HttpBin::socksProxyUrl('/get'))
                    ->wait()
            )->toThrow(NetworkException::class);
        });

        it('throws on unreachable SOCKS4 proxy', function () {
            expect(
                fn () => ProxySetup::client(timeout: 3)
                    ->withSocks4Proxy('127.0.0.1', 19997)
                    ->get(HttpBin::socksProxyUrl('/get'))
                    ->wait()
            )->toThrow(NetworkException::class);
        });

        it('throws on wrong SOCKS5 credentials', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::socks5Host(), ProxySetup::socks5Port());

            if (ProxySetup::socks5User() === null) {
                test()->markTestSkipped('SOCKS5_USER not set — skipping credential rejection test');
            }

            expect(
                fn () => ProxySetup::client(timeout: 3)
                    ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port(), 'wrong_user', 'wrong_pass')
                    ->get(HttpBin::socksProxyUrl('/get'))
                    ->wait()
            )->toThrow(NetworkException::class);
        });
    });

    describe('streaming through proxy', function () {

        beforeEach(function () {
            HttpBin::skipIfUnreachable();
        });

        it('streams multiple JSON objects through HTTP proxy', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::httpHost(), ProxySetup::httpPort());

            $response = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->get(HttpBin::proxyUrl('/stream/5'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue();

            $lines = array_filter(explode("\n", trim($response->body())));
            expect(count($lines))->toBe(5);

            foreach ($lines as $line) {
                $decoded = json_decode($line, true);
                expect($decoded)->toBeArray()
                    ->and(array_key_exists('id', $decoded))->toBeTrue()
                ;
            }
        });

        it('streams multiple JSON objects through SOCKS5', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::socks5Host(), ProxySetup::socks5Port());

            $response = ProxySetup::client()
                ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port(), ProxySetup::socks5User(), ProxySetup::socks5Pass())
                ->get(HttpBin::socksProxyUrl('/stream/5'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue();

            $lines = array_filter(explode("\n", trim($response->body())));
            expect(count($lines))->toBe(5);

            foreach ($lines as $line) {
                $decoded = json_decode($line, true);
                expect($decoded)->toBeArray()
                    ->and(array_key_exists('id', $decoded))->toBeTrue()
                ;
            }
        });

        it('streams multiple JSON objects through SOCKS4', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::socks4Host(), ProxySetup::socks4Port());

            $response = ProxySetup::client()
                ->withSocks4Proxy(ProxySetup::socks4Host(), ProxySetup::socks4Port())
                ->get(HttpBin::socksProxyUrl('/stream/5'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue();

            $lines = array_filter(explode("\n", trim($response->body())));
            expect(count($lines))->toBe(5);

            foreach ($lines as $line) {
                $decoded = json_decode($line, true);
                expect($decoded)->toBeArray()
                    ->and(array_key_exists('id', $decoded))->toBeTrue()
                ;
            }
        });

        it('streams raw bytes through HTTP proxy', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::httpHost(), ProxySetup::httpPort());

            $response = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->get(HttpBin::proxyUrl('/stream-bytes/1024'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and(strlen($response->body()))->toBe(1024)
            ;
        });

        it('streams raw bytes through SOCKS5', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::socks5Host(), ProxySetup::socks5Port());

            $response = ProxySetup::client()
                ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port(), ProxySetup::socks5User(), ProxySetup::socks5Pass())
                ->get(HttpBin::socksProxyUrl('/stream-bytes/1024'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and(strlen($response->body()))->toBe(1024)
            ;
        });

        it('streams raw bytes through SOCKS4', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::socks4Host(), ProxySetup::socks4Port());

            $response = ProxySetup::client()
                ->withSocks4Proxy(ProxySetup::socks4Host(), ProxySetup::socks4Port())
                ->get(HttpBin::socksProxyUrl('/stream-bytes/1024'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and(strlen($response->body()))->toBe(1024)
            ;
        });
    });

    describe('download through proxy', function () {

        beforeEach(function () {
            HttpBin::skipIfUnreachable();
        });

        it('downloads binary bytes through HTTP proxy', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::httpHost(), ProxySetup::httpPort());

            $response = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->get(HttpBin::proxyUrl('/bytes/2048'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and(strlen($response->body()))->toBe(2048)
            ;
        });

        it('downloads binary bytes through SOCKS5', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::socks5Host(), ProxySetup::socks5Port());

            $response = ProxySetup::client()
                ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port(), ProxySetup::socks5User(), ProxySetup::socks5Pass())
                ->get(HttpBin::socksProxyUrl('/bytes/2048'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and(strlen($response->body()))->toBe(2048)
            ;
        });

        it('downloads binary bytes through SOCKS4', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::socks4Host(), ProxySetup::socks4Port());

            $response = ProxySetup::client()
                ->withSocks4Proxy(ProxySetup::socks4Host(), ProxySetup::socks4Port())
                ->get(HttpBin::socksProxyUrl('/bytes/2048'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and(strlen($response->body()))->toBe(2048)
            ;
        });

        it('downloads a PNG image through HTTP proxy', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::httpHost(), ProxySetup::httpPort());

            $response = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->withHeader('Accept', 'image/png')
                ->get(HttpBin::proxyUrl('/image/png'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and($response->header('Content-Type'))->toContain('image/png')
                ->and(strlen($response->body()))->toBeGreaterThan(0)
            ;
        });

        it('downloads a PNG image through SOCKS5', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::socks5Host(), ProxySetup::socks5Port());

            $response = ProxySetup::client()
                ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port(), ProxySetup::socks5User(), ProxySetup::socks5Pass())
                ->withHeader('Accept', 'image/png')
                ->get(HttpBin::socksProxyUrl('/image/png'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and($response->header('Content-Type'))->toContain('image/png')
                ->and(strlen($response->body()))->toBeGreaterThan(0)
            ;
        });

        it('downloads a PNG image through SOCKS4', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::socks4Host(), ProxySetup::socks4Port());

            $response = ProxySetup::client()
                ->withSocks4Proxy(ProxySetup::socks4Host(), ProxySetup::socks4Port())
                ->withHeader('Accept', 'image/png')
                ->get(HttpBin::socksProxyUrl('/image/png'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and($response->header('Content-Type'))->toContain('image/png')
                ->and(strlen($response->body()))->toBeGreaterThan(0)
            ;
        });
    });

    describe('upload through proxy', function () {

        beforeEach(function () {
            HttpBin::skipIfUnreachable();
        });

        it('uploads a file via multipart form through HTTP proxy', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::httpHost(), ProxySetup::httpPort());

            $content = str_repeat('proxy-upload-test', 100);
            $tmpFile = tempnam(sys_get_temp_dir(), 'proxy_upload_');
            file_put_contents($tmpFile, $content);

            try {
                $response = ProxySetup::client()
                    ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                    ->withFile('file', $tmpFile, 'test.txt')
                    ->post(HttpBin::proxyUrl('/post'))
                    ->wait()
                ;

                expect($response->successful())->toBeTrue()
                    ->and($response->json('files.file'))->not->toBeEmpty()
                ;
            } finally {
                @unlink($tmpFile);
            }
        });

        it('uploads a file via multipart form through SOCKS5', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::socks5Host(), ProxySetup::socks5Port());

            $content = str_repeat('socks5-upload-test', 100);
            $tmpFile = tempnam(sys_get_temp_dir(), 'proxy_upload_');
            file_put_contents($tmpFile, $content);

            try {
                $response = ProxySetup::client()
                    ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port(), ProxySetup::socks5User(), ProxySetup::socks5Pass())
                    ->withFile('file', $tmpFile, 'test.txt')
                    ->post(HttpBin::socksProxyUrl('/post'))
                    ->wait()
                ;

                expect($response->successful())->toBeTrue()
                    ->and($response->json('files.file'))->not->toBeEmpty()
                ;
            } finally {
                @unlink($tmpFile);
            }
        });

        it('uploads a file via multipart form through SOCKS4', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::socks4Host(), ProxySetup::socks4Port());

            $content = str_repeat('socks4-upload-test', 100);
            $tmpFile = tempnam(sys_get_temp_dir(), 'proxy_upload_');
            file_put_contents($tmpFile, $content);

            try {
                $response = ProxySetup::client()
                    ->withSocks4Proxy(ProxySetup::socks4Host(), ProxySetup::socks4Port())
                    ->withFile('file', $tmpFile, 'test.txt')
                    ->post(HttpBin::socksProxyUrl('/post'))
                    ->wait()
                ;

                expect($response->successful())->toBeTrue()
                    ->and($response->json('files.file'))->not->toBeEmpty()
                ;
            } finally {
                @unlink($tmpFile);
            }
        });

        it('uploads raw binary body through HTTP proxy', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::httpHost(), ProxySetup::httpPort());

            $binary = random_bytes(512);

            $response = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->contentType('application/octet-stream')
                ->body($binary)
                ->post(HttpBin::proxyUrl('/post'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and($response->json('data'))->not->toBeEmpty()
            ;
        });

        it('uploads raw binary body through SOCKS5', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::socks5Host(), ProxySetup::socks5Port());

            $binary = random_bytes(512);

            $response = ProxySetup::client()
                ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port(), ProxySetup::socks5User(), ProxySetup::socks5Pass())
                ->contentType('application/octet-stream')
                ->body($binary)
                ->post(HttpBin::socksProxyUrl('/post'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and($response->json('data'))->not->toBeEmpty()
            ;
        });

        it('uploads raw binary body through SOCKS4', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::socks4Host(), ProxySetup::socks4Port());

            $binary = random_bytes(512);

            $response = ProxySetup::client()
                ->withSocks4Proxy(ProxySetup::socks4Host(), ProxySetup::socks4Port())
                ->contentType('application/octet-stream')
                ->body($binary)
                ->post(HttpBin::socksProxyUrl('/post'))
                ->wait()
            ;

            expect($response->successful())->toBeTrue()
                ->and($response->json('data'))->not->toBeEmpty()
            ;
        });
    });

    describe('SSE through proxy', function () {

        beforeEach(function () {
            HttpBin::skipIfUnreachable();
        });

        it('connects to an SSE endpoint through HTTP proxy', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::httpHost(), ProxySetup::httpPort());

            ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->sse(HttpBin::proxyUrl('/get'))
                ->connect()
                ->wait()
            ;

            expect(true)->toBeTrue();
        });

        it('connects to an SSE endpoint through SOCKS5', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::socks5Host(), ProxySetup::socks5Port());

            ProxySetup::client()
                ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port(), ProxySetup::socks5User(), ProxySetup::socks5Pass())
                ->sse(HttpBin::socksProxyUrl('/get'))
                ->connect()
                ->wait()
            ;

            expect(true)->toBeTrue();
        });

        it('connects to an SSE endpoint through SOCKS4', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::socks4Host(), ProxySetup::socks4Port());

            ProxySetup::client()
                ->withSocks4Proxy(ProxySetup::socks4Host(), ProxySetup::socks4Port())
                ->sse(HttpBin::socksProxyUrl('/get'))
                ->connect()
                ->wait()
            ;

            expect(true)->toBeTrue();
        });

        it('forwards custom headers during SSE handshake through HTTP proxy', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::httpHost(), ProxySetup::httpPort());

            ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->withHeader('X-SSE-Proxy', 'http')
                ->sse(HttpBin::proxyUrl('/headers'))
                ->connect()
                ->wait()
            ;

            expect(true)->toBeTrue();
        });

        it('SSE handshake through unreachable HTTP proxy throws', function () {
            expect(
                fn () => ProxySetup::client(timeout: 3)
                    ->withProxy('127.0.0.1', 19996)
                    ->sse(HttpBin::proxyUrl('/get'))
                    ->connect()
                    ->wait()
            )->toThrow(NetworkException::class);
        });

        it('SSE handshake through unreachable SOCKS5 proxy throws', function () {
            expect(
                fn () => ProxySetup::client(timeout: 3)
                    ->withSocks5Proxy('127.0.0.1', 19995)
                    ->sse(HttpBin::socksProxyUrl('/get'))
                    ->connect()
                    ->wait()
            )->toThrow(NetworkException::class);
        });

        it('SSE handshake through unreachable SOCKS4 proxy throws', function () {
            expect(
                fn () => ProxySetup::client(timeout: 3)
                    ->withSocks4Proxy('127.0.0.1', 19994)
                    ->sse(HttpBin::socksProxyUrl('/get'))
                    ->connect()
                    ->wait()
            )->toThrow(NetworkException::class);
        });
    });
});
