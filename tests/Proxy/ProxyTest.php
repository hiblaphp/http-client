<?php

declare(strict_types=1);

use Hibla\HttpClient\ValueObjects\ProxyConfig;
use Tests\Fixtures\HttpBinFixture;
use Tests\Fixtures\ProxyFixture;

// ── ProxyConfig value object tests ────────────────────────────────────────────

describe('ProxyConfig value object', function () {

    it('builds an HTTP proxy URL without credentials', function () {
        $config = ProxyConfig::http('proxy.local', 8080);

        expect($config->getProxyUrl())->toBe('http://proxy.local:8080')
            ->and($config->type)->toBe('http')
            ->and($config->username)->toBeNull()
            ->and($config->password)->toBeNull();
    });

    it('builds an HTTP proxy URL with credentials', function () {
        $config = ProxyConfig::http('proxy.local', 8080, 'user', 'secret');

        expect($config->getProxyUrl())->toBe('http://user:secret@proxy.local:8080');
    });

    it('builds a SOCKS4 proxy URL — no password field', function () {
        $config = ProxyConfig::socks4('10.0.0.1', 1080, 'user');

        expect($config->getProxyUrl())->toBe('socks4://user@10.0.0.1:1080')
            ->and($config->password)->toBeNull();
    });

    it('builds a SOCKS5 proxy URL with credentials', function () {
        $config = ProxyConfig::socks5('10.0.0.1', 1080, 'user', 'pass');

        expect($config->getProxyUrl())->toBe('socks5://user:pass@10.0.0.1:1080');
    });

    it('builds a SOCKS5 proxy URL without credentials', function () {
        $config = ProxyConfig::socks5('10.0.0.1', 1080);

        expect($config->getProxyUrl())->toBe('socks5://10.0.0.1:1080')
            ->and($config->username)->toBeNull()
            ->and($config->password)->toBeNull();
    });

    it('returns correct cURL proxy type constants', function () {
        expect(ProxyConfig::http('h', 1)->getCurlProxyType())->toBe(CURLPROXY_HTTP)
            ->and(ProxyConfig::socks4('h', 1)->getCurlProxyType())->toBe(CURLPROXY_SOCKS4)
            ->and(ProxyConfig::socks5('h', 1)->getCurlProxyType())->toBe(CURLPROXY_SOCKS5);
    });
});

// ── Immutability & chain tests ────────────────────────────────────────────────

describe('HttpClient proxy chain immutability', function () {

    it('withProxy() returns a new instance', function () {
        $a = ProxyFixture::client();
        $b = $a->withProxy(ProxyFixture::httpHost(), ProxyFixture::httpPort());

        expect($b)->not->toBe($a);
    });

    it('noProxy() strips a previously configured proxy', function () {
        $stripped = ProxyFixture::client()
            ->withProxy(ProxyFixture::httpHost(), ProxyFixture::httpPort())
            ->noProxy();

        expect(ProxyFixture::readPrivate($stripped, 'proxyConfig'))->toBeNull();
    });

    it('proxyWith() stores a hand-crafted ProxyConfig', function () {
        $config = new ProxyConfig(
            host: 'custom.proxy',
            port: 9090,
            username: 'admin',
            password: 'pw',
            type: 'socks5',
        );

        $client = ProxyFixture::client()->proxyWith($config);

        expect(ProxyFixture::readPrivate($client, 'proxyConfig'))->toBe($config);
    });

    it('does not mutate the base instance when branching', function () {
        $base      = ProxyFixture::client()->withToken('tok')->asJson();
        $proxied   = $base->withProxy(ProxyFixture::httpHost(), ProxyFixture::httpPort());
        $unproxied = $base->noProxy();

        expect(ProxyFixture::readPrivate($proxied, 'proxyConfig'))->not->toBeNull()
            ->and(ProxyFixture::readPrivate($unproxied, 'proxyConfig'))->toBeNull()
            ->and(ProxyFixture::readPrivate($base, 'proxyConfig'))->toBeNull();
    });

    it('the last withProxy() call wins', function () {
        $client = ProxyFixture::client()
            ->withProxy('first.proxy', 8080)
            ->withProxy('second.proxy', 9090);

        /** @var ProxyConfig $config */
        $config = ProxyFixture::readPrivate($client, 'proxyConfig');

        expect($config->host)->toBe('second.proxy')
            ->and($config->port)->toBe(9090);
    });

    it('switching proxy type mid-chain stores the latest type', function () {
        $client = ProxyFixture::client()
            ->withProxy('http.proxy', 8080)
            ->withSocks5Proxy('socks.proxy', 1080);

        /** @var ProxyConfig $config */
        $config = ProxyFixture::readPrivate($client, 'proxyConfig');

        expect($config->type)->toBe('socks5')
            ->and($config->host)->toBe('socks.proxy');
    });
});

// ── HTTP proxy (Squid) integration tests ─────────────────────────────────────

describe('HTTP proxy — Squid', function () {

    beforeEach(function () {
        HttpBinFixture::skipIfUnreachable();
        ProxyFixture::skipIfUnreachable(
            ProxyFixture::httpHost(),
            ProxyFixture::httpPort(),
        );
    });

    it('routes GET /get through the HTTP proxy', function () {
        $response = ProxyFixture::client()
            ->withProxy(ProxyFixture::httpHost(), ProxyFixture::httpPort())
            ->get(HttpBinFixture::proxyUrl('/get'))
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and($response->json('url'))->not->toBeEmpty();
    });

    it('forwards custom headers through the proxy', function () {
        $response = ProxyFixture::client()
            ->withProxy(ProxyFixture::httpHost(), ProxyFixture::httpPort())
            ->withHeader('X-Proxy-Test', 'squid')
            ->get(HttpBinFixture::proxyUrl('/headers'))
            ->wait();

        // go-httpbin returns header values as arrays
        expect($response->successful())->toBeTrue()
            ->and($response->json('headers.X-Proxy-Test.0'))->toBe('squid');
    });

    it('POSTs JSON through the HTTP proxy', function () {
        $response = ProxyFixture::client()
            ->withProxy(ProxyFixture::httpHost(), ProxyFixture::httpPort())
            ->post(HttpBinFixture::proxyUrl('/post'), ['proxy' => 'http', 'framework' => 'pest'])
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and($response->json('json.proxy'))->toBe('http')
            ->and($response->json('json.framework'))->toBe('pest');
    });

    it('PUTs JSON through the HTTP proxy', function () {
        $response = ProxyFixture::client()
            ->withProxy(ProxyFixture::httpHost(), ProxyFixture::httpPort())
            ->put(HttpBinFixture::proxyUrl('/put'), ['action' => 'update'])
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and($response->json('json.action'))->toBe('update');
    });

    it('DELETEs through the HTTP proxy', function () {
        $response = ProxyFixture::client()
            ->withProxy(ProxyFixture::httpHost(), ProxyFixture::httpPort())
            ->delete(HttpBinFixture::proxyUrl('/delete'))
            ->wait();

        expect($response->successful())->toBeTrue();
    });

    it('noProxy() branch bypasses the proxy on the same chain', function () {
        $base = ProxyFixture::client()
            ->withProxy(ProxyFixture::httpHost(), ProxyFixture::httpPort());

        // Direct from host — use url() not proxyUrl()
        $response = $base->noProxy()
            ->get(HttpBinFixture::url('/get'))
            ->wait();

        expect($response->successful())->toBeTrue();
    });

    it('follows redirects through the proxy', function () {
        $response = ProxyFixture::client()
            ->withProxy(ProxyFixture::httpHost(), ProxyFixture::httpPort())
            ->redirects(true, 5)
            ->get(HttpBinFixture::proxyUrl('/absolute-redirect/2'))
            ->wait();

        expect($response->successful())->toBeTrue();
    });
});

// ── SOCKS5 proxy integration tests ───────────────────────────────────────────

describe('SOCKS5 proxy', function () {

    beforeEach(function () {
        HttpBinFixture::skipIfUnreachable();
        ProxyFixture::skipIfUnreachable(
            ProxyFixture::socks5Host(),
            ProxyFixture::socks5Port(),
        );
    });

    it('routes GET /get through the SOCKS5 proxy', function () {
        $response = ProxyFixture::client()
            ->withSocks5Proxy(ProxyFixture::socks5Host(), ProxyFixture::socks5Port())
            ->get(HttpBinFixture::socksProxyUrl('/get'))
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and($response->json('url'))->not->toBeEmpty();
    });

    it('POSTs JSON through SOCKS5', function () {
        $response = ProxyFixture::client()
            ->withSocks5Proxy(ProxyFixture::socks5Host(), ProxyFixture::socks5Port())
            ->post(HttpBinFixture::socksProxyUrl('/post'), ['via' => 'socks5'])
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and($response->json('json.via'))->toBe('socks5');
    });

    it('forwards custom headers through SOCKS5', function () {
        $response = ProxyFixture::client()
            ->withSocks5Proxy(ProxyFixture::socks5Host(), ProxyFixture::socks5Port())
            ->withHeader('X-Proxy-Test', 'socks5')
            ->get(HttpBinFixture::socksProxyUrl('/headers'))
            ->wait();

        // go-httpbin returns header values as arrays
        expect($response->successful())->toBeTrue()
            ->and($response->json('headers.X-Proxy-Test.0'))->toBe('socks5');
    });

    it('authenticates with SOCKS5 when credentials are set', function () {
        $user = ProxyFixture::socks5User();
        $pass = ProxyFixture::socks5Pass();

        if ($user === null) {
            test()->markTestSkipped('SOCKS5_USER not set in .env — skipping auth test');
        }

        $response = ProxyFixture::client()
            ->withSocks5Proxy(
                ProxyFixture::socks5Host(),
                ProxyFixture::socks5Port(),
                $user,
                $pass,
            )
            ->get(HttpBinFixture::socksProxyUrl('/get'))
            ->wait();

        expect($response->successful())->toBeTrue();
    });
});

// ── SOCKS4 proxy integration tests ───────────────────────────────────────────

describe('SOCKS4 proxy', function () {

    beforeEach(function () {
        HttpBinFixture::skipIfUnreachable();
        ProxyFixture::skipIfUnreachable(
            ProxyFixture::socks4Host(),
            ProxyFixture::socks4Port(),
        );
    });

    it('routes GET /get through the SOCKS4 proxy', function () {
        $response = ProxyFixture::client()
            ->withSocks4Proxy(ProxyFixture::socks4Host(), ProxyFixture::socks4Port())
            ->get(HttpBinFixture::socksProxyUrl('/get'))
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and($response->json('url'))->not->toBeEmpty();
    });

    it('POSTs JSON through SOCKS4', function () {
        $response = ProxyFixture::client()
            ->withSocks4Proxy(ProxyFixture::socks4Host(), ProxyFixture::socks4Port())
            ->post(HttpBinFixture::socksProxyUrl('/post'), ['via' => 'socks4'])
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and($response->json('json.via'))->toBe('socks4');
    });
});

// ── Proxy switching tests ─────────────────────────────────────────────────────

describe('proxy switching across types', function () {

    beforeEach(function () {
        HttpBinFixture::skipIfUnreachable();
        ProxyFixture::skipIfUnreachable(ProxyFixture::httpHost(), ProxyFixture::httpPort());
        ProxyFixture::skipIfUnreachable(ProxyFixture::socks5Host(), ProxyFixture::socks5Port());
    });

    it('can branch from HTTP proxy to SOCKS5 proxy off the same base client', function () {
        $base = ProxyFixture::client()->withToken('test-token');

        $viaHttp = $base
            ->withProxy(ProxyFixture::httpHost(), ProxyFixture::httpPort())
            ->get(HttpBinFixture::proxyUrl('/get'))
            ->wait();

        $viaSocks5 = $base
            ->withSocks5Proxy(ProxyFixture::socks5Host(), ProxyFixture::socks5Port())
            ->get(HttpBinFixture::socksProxyUrl('/get'))
            ->wait();

        expect($viaHttp->successful())->toBeTrue()
            ->and($viaSocks5->successful())->toBeTrue();
    });

    it('base client is unaffected after branching', function () {
        $base = ProxyFixture::client();

        $base->withProxy(ProxyFixture::httpHost(), ProxyFixture::httpPort())
            ->get(HttpBinFixture::proxyUrl('/get'))
            ->wait();

        expect(ProxyFixture::readPrivate($base, 'proxyConfig'))->toBeNull();
    });
});