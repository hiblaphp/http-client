<?php

declare(strict_types=1);

use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\ValueObjects\ProxyConfig;
use Tests\Fixtures\HttpBin;
use Tests\Fixtures\ProxySetup;

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
            ->and(ProxyConfig::socks4('h', 1)->getCurlProxyType())->toBe(CURLPROXY_SOCKS4A)
            ->and(ProxyConfig::socks5('h', 1)->getCurlProxyType())->toBe(CURLPROXY_SOCKS5_HOSTNAME);
    });
});

describe('HttpClient proxy chain immutability', function () {

    it('withProxy() returns a new instance', function () {
        $a = ProxySetup::client();
        $b = $a->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort());

        expect($b)->not->toBe($a);
    });

    it('noProxy() strips a previously configured proxy', function () {
        $stripped = ProxySetup::client()
            ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
            ->noProxy();

        expect(ProxySetup::readPrivate($stripped, 'proxyConfig'))->toBeNull();
    });

    it('proxyWith() stores a hand-crafted ProxyConfig', function () {
        $config = new ProxyConfig(
            host: 'custom.proxy',
            port: 9090,
            username: 'admin',
            password: 'pw',
            type: 'socks5',
        );

        $client = ProxySetup::client()->proxyWith($config);

        expect(ProxySetup::readPrivate($client, 'proxyConfig'))->toBe($config);
    });

    it('does not mutate the base instance when branching', function () {
        $base      = ProxySetup::client()->withToken('tok')->asJson();
        $proxied   = $base->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort());
        $unproxied = $base->noProxy();

        expect(ProxySetup::readPrivate($proxied, 'proxyConfig'))->not->toBeNull()
            ->and(ProxySetup::readPrivate($unproxied, 'proxyConfig'))->toBeNull()
            ->and(ProxySetup::readPrivate($base, 'proxyConfig'))->toBeNull();
    });

    it('the last withProxy() call wins', function () {
        $client = ProxySetup::client()
            ->withProxy('first.proxy', 8080)
            ->withProxy('second.proxy', 9090);

        /** @var ProxyConfig $config */
        $config = ProxySetup::readPrivate($client, 'proxyConfig');

        expect($config->host)->toBe('second.proxy')
            ->and($config->port)->toBe(9090);
    });

    it('switching proxy type mid-chain stores the latest type', function () {
        $client = ProxySetup::client()
            ->withProxy('http.proxy', 8080)
            ->withSocks5Proxy('socks.proxy', 1080);

        /** @var ProxyConfig $config */
        $config = ProxySetup::readPrivate($client, 'proxyConfig');

        expect($config->type)->toBe('socks5')
            ->and($config->host)->toBe('socks.proxy');
    });
});

describe('HTTP proxy — Squid', function () {

    beforeEach(function () {
        HttpBin::skipIfUnreachable();
        ProxySetup::skipIfUnreachable(
            ProxySetup::httpHost(),
            ProxySetup::httpPort(),
        );
    });

    it('routes GET /get through the HTTP proxy', function () {
        $response = ProxySetup::client()
            ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
            ->get(HttpBin::proxyUrl('/get'))
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and($response->json('url'))->not->toBeEmpty();
    });

    it('forwards custom headers through the proxy', function () {
        $response = ProxySetup::client()
            ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
            ->withHeader('X-Proxy-Test', 'squid')
            ->get(HttpBin::proxyUrl('/headers'))
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and($response->json('headers.X-Proxy-Test.0'))->toBe('squid');
    });

    it('POSTs JSON through the HTTP proxy', function () {
        $response = ProxySetup::client()
            ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
            ->post(HttpBin::proxyUrl('/post'), ['proxy' => 'http', 'framework' => 'pest'])
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and($response->json('json.proxy'))->toBe('http')
            ->and($response->json('json.framework'))->toBe('pest');
    });

    it('PUTs JSON through the HTTP proxy', function () {
        $response = ProxySetup::client()
            ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
            ->put(HttpBin::proxyUrl('/put'), ['action' => 'update'])
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and($response->json('json.action'))->toBe('update');
    });

    it('DELETEs through the HTTP proxy', function () {
        $response = ProxySetup::client()
            ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
            ->delete(HttpBin::proxyUrl('/delete'))
            ->wait();

        expect($response->successful())->toBeTrue();
    });

    it('noProxy() branch bypasses the proxy on the same chain', function () {
        $base = ProxySetup::client()
            ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort());

        $response = $base->noProxy()
            ->get(HttpBin::url('/get'))
            ->wait();

        expect($response->successful())->toBeTrue();
    });

    it('follows redirects through the proxy', function () {
        $response = ProxySetup::client()
            ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
            ->redirects(true, 5)
            ->get(HttpBin::proxyUrl('/absolute-redirect/2'))
            ->wait();

        expect($response->successful())->toBeTrue();
    });
});

describe('SOCKS5 proxy', function () {

    beforeEach(function () {
        HttpBin::skipIfUnreachable();
        ProxySetup::skipIfUnreachable(
            ProxySetup::socks5Host(),
            ProxySetup::socks5Port(),
        );
    });

    it('routes GET /get through the SOCKS5 proxy', function () {
        $response = ProxySetup::client()
            ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port())
            ->get(HttpBin::socksProxyUrl('/get'))
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and($response->json('url'))->not->toBeEmpty();
    });

    it('POSTs JSON through SOCKS5', function () {
        $response = ProxySetup::client()
            ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port())
            ->post(HttpBin::socksProxyUrl('/post'), ['via' => 'socks5'])
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and($response->json('json.via'))->toBe('socks5');
    });

    it('forwards custom headers through SOCKS5', function () {
        $response = ProxySetup::client()
            ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port())
            ->withHeader('X-Proxy-Test', 'socks5')
            ->get(HttpBin::socksProxyUrl('/headers'))
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and($response->json('headers.X-Proxy-Test.0'))->toBe('socks5');
    });

    it('authenticates with SOCKS5 when credentials are set', function () {
        $user = ProxySetup::socks5User();
        $pass = ProxySetup::socks5Pass();

        if ($user === null) {
            test()->markTestSkipped('SOCKS5_USER not set in .env — skipping auth test');
        }

        $response = ProxySetup::client()
            ->withSocks5Proxy(
                ProxySetup::socks5Host(),
                ProxySetup::socks5Port(),
                $user,
                $pass,
            )
            ->get(HttpBin::socksProxyUrl('/get'))
            ->wait();

        expect($response->successful())->toBeTrue();
    });
});

describe('SOCKS4 proxy', function () {

    beforeEach(function () {
        HttpBin::skipIfUnreachable();
        ProxySetup::skipIfUnreachable(
            ProxySetup::socks4Host(),
            ProxySetup::socks4Port(),
        );
    });

    it('routes GET /get through the SOCKS4 proxy', function () {
        $response = ProxySetup::client()
            ->withSocks4Proxy(ProxySetup::socks4Host(), ProxySetup::socks4Port())
            ->get(HttpBin::socksProxyUrl('/get'))
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and($response->json('url'))->not->toBeEmpty();
    });

    it('POSTs JSON through SOCKS4', function () {
        $response = ProxySetup::client()
            ->withSocks4Proxy(ProxySetup::socks4Host(), ProxySetup::socks4Port())
            ->post(HttpBin::socksProxyUrl('/post'), ['via' => 'socks4'])
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and($response->json('json.via'))->toBe('socks4');
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
            ->wait();

        $viaSocks5 = $base
            ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port())
            ->get(HttpBin::socksProxyUrl('/get'))
            ->wait();

        expect($viaHttp->successful())->toBeTrue()
            ->and($viaSocks5->successful())->toBeTrue();
    });

    it('base client is unaffected after branching', function () {
        $base = ProxySetup::client();

        $base->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
            ->get(HttpBin::proxyUrl('/get'))
            ->wait();

        expect(ProxySetup::readPrivate($base, 'proxyConfig'))->toBeNull();
    });
});

describe('large payload proxying', function () {

    beforeEach(function () {
        HttpBin::skipIfUnreachable();
    });

    it('sends a large JSON payload through the HTTP proxy', function () {
        ProxySetup::skipIfUnreachable(ProxySetup::httpHost(), ProxySetup::httpPort());

        $payload = ['data' => str_repeat('x', 10_000)];

        $response = ProxySetup::client()
            ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
            ->post(HttpBin::proxyUrl('/post'), $payload)
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and(strlen($response->json('json.data')))->toBe(10_000);
    });

    it('sends a large JSON payload through SOCKS5', function () {
        ProxySetup::skipIfUnreachable(ProxySetup::socks5Host(), ProxySetup::socks5Port());

        $payload = ['data' => str_repeat('y', 10_000)];

        $response = ProxySetup::client()
            ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port())
            ->post(HttpBin::socksProxyUrl('/post'), $payload)
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and(strlen($response->json('json.data')))->toBe(10_000);
    });

    it('sends a large JSON payload through SOCKS4', function () {
        ProxySetup::skipIfUnreachable(ProxySetup::socks4Host(), ProxySetup::socks4Port());

        $payload = ['data' => str_repeat('z', 10_000)];

        $response = ProxySetup::client()
            ->withSocks4Proxy(ProxySetup::socks4Host(), ProxySetup::socks4Port())
            ->post(HttpBin::socksProxyUrl('/post'), $payload)
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and(strlen($response->json('json.data')))->toBe(10_000);
    });
});

describe('query string preservation through proxy', function () {

    beforeEach(function () {
        HttpBin::skipIfUnreachable();
    });

    it('preserves query parameters through the HTTP proxy', function () {
        ProxySetup::skipIfUnreachable(ProxySetup::httpHost(), ProxySetup::httpPort());

        $response = ProxySetup::client()
            ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
            ->get(HttpBin::proxyUrl('/get') . '?foo=bar&baz=qux')
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and($response->json('args.foo.0'))->toBe('bar')
            ->and($response->json('args.baz.0'))->toBe('qux');
    });

    it('preserves query parameters through SOCKS5', function () {
        ProxySetup::skipIfUnreachable(ProxySetup::socks5Host(), ProxySetup::socks5Port());

        $response = ProxySetup::client()
            ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port())
            ->get(HttpBin::socksProxyUrl('/get') . '?via=socks5&test=1')
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and($response->json('args.via.0'))->toBe('socks5')
            ->and($response->json('args.test.0'))->toBe('1');
    });

    it('preserves query parameters through SOCKS4', function () {
        ProxySetup::skipIfUnreachable(ProxySetup::socks4Host(), ProxySetup::socks4Port());

        $response = ProxySetup::client()
            ->withSocks4Proxy(ProxySetup::socks4Host(), ProxySetup::socks4Port())
            ->get(HttpBin::socksProxyUrl('/get') . '?via=socks4&test=1')
            ->wait();

        expect($response->successful())->toBeTrue()
            ->and($response->json('args.via.0'))->toBe('socks4')
            ->and($response->json('args.test.0'))->toBe('1');
    });
});

describe('redirect handling through SOCKS proxies', function () {

    beforeEach(function () {
        HttpBin::skipIfUnreachable();
    });

    it('follows redirects through SOCKS5', function () {
        ProxySetup::skipIfUnreachable(ProxySetup::socks5Host(), ProxySetup::socks5Port());

        $response = ProxySetup::client()
            ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port())
            ->redirects(true, 5)
            ->get(HttpBin::socksProxyUrl('/absolute-redirect/2'))
            ->wait();

        expect($response->successful())->toBeTrue();
    });

    it('follows redirects through SOCKS4', function () {
        ProxySetup::skipIfUnreachable(ProxySetup::socks4Host(), ProxySetup::socks4Port());

        $response = ProxySetup::client()
            ->withSocks4Proxy(ProxySetup::socks4Host(), ProxySetup::socks4Port())
            ->redirects(true, 5)
            ->get(HttpBin::socksProxyUrl('/absolute-redirect/2'))
            ->wait();

        expect($response->successful())->toBeTrue();
    });

    it('does not follow redirects when disabled through proxy', function () {
        ProxySetup::skipIfUnreachable(ProxySetup::httpHost(), ProxySetup::httpPort());

        $response = ProxySetup::client()
            ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
            ->redirects(false)
            ->get(HttpBin::proxyUrl('/absolute-redirect/1'))
            ->wait();

        expect($response->status())->toBe(302);
    });
});

describe('empty response body through proxy', function () {

    beforeEach(function () {
        HttpBin::skipIfUnreachable();
    });

    it('handles 204 No Content through HTTP proxy', function () {
        ProxySetup::skipIfUnreachable(ProxySetup::httpHost(), ProxySetup::httpPort());

        $response = ProxySetup::client()
            ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
            ->get(HttpBin::proxyUrl('/status/204'))
            ->wait();

        expect($response->status())->toBe(204)
            ->and($response->body())->toBe('');
    });

    it('handles 204 No Content through SOCKS5', function () {
        ProxySetup::skipIfUnreachable(ProxySetup::socks5Host(), ProxySetup::socks5Port());

        $response = ProxySetup::client()
            ->withSocks5Proxy(ProxySetup::socks5Host(), ProxySetup::socks5Port())
            ->get(HttpBin::socksProxyUrl('/status/204'))
            ->wait();

        expect($response->status())->toBe(204)
            ->and($response->body())->toBe('');
    });
});

describe('graceful failure on unreachable or bad proxy', function () {

    beforeEach(function () {
        HttpBin::skipIfUnreachable();
    });

    it('throws on unreachable HTTP proxy', function () {
        expect(
            fn() => ProxySetup::client(timeout: 3)
                ->withProxy('127.0.0.1', 19999)
                ->get(HttpBin::url('/get'))
                ->wait()
        )->toThrow(NetworkException::class);
    });

    it('throws on unreachable SOCKS5 proxy', function () {
        expect(
            fn() => ProxySetup::client(timeout: 3)
                ->withSocks5Proxy('127.0.0.1', 19998)
                ->get(HttpBin::socksProxyUrl('/get'))
                ->wait()
        )->toThrow(NetworkException::class);
    });

    it('throws on unreachable SOCKS4 proxy', function () {
        expect(
            fn() => ProxySetup::client(timeout: 3)
                ->withSocks4Proxy('127.0.0.1', 19997)
                ->get(HttpBin::socksProxyUrl('/get'))
                ->wait()
        )->toThrow(NetworkException::class);
    });

    it('throws on wrong SOCKS5 credentials', function () {
        ProxySetup::skipIfUnreachable(ProxySetup::socks5Host(), ProxySetup::socks5Port());

        $user = ProxySetup::socks5User();

        if ($user === null) {
            test()->markTestSkipped('SOCKS5_USER not set — skipping credential rejection test');
        }

        expect(
            fn() => ProxySetup::client(timeout: 3)
                ->withSocks5Proxy(
                    ProxySetup::socks5Host(),
                    ProxySetup::socks5Port(),
                    'wrong_user',
                    'wrong_pass',
                )
                ->get(HttpBin::socksProxyUrl('/get'))
                ->wait()
        )->toThrow(NetworkException::class);
    });
});

describe('proxy security', function () {
    it('does not expose password in ProxyConfig toString or url when inspected', function () {
        $config = ProxyConfig::http('proxy.local', 8080, 'user', 'secret_password');

        expect($config->host)->toBe('proxy.local')
            ->and($config->port)->toBe(8080)
            ->and($config->type)->toBe('http')
            ->and($config->username)->toBe('user')
            ->and($config->host)->not->toContain('secret_password')
            ->and($config->type)->not->toContain('secret_password');
    });

    it('SOCKS4 does not accept a password field', function () {
        $config = ProxyConfig::socks4('proxy.local', 1080, 'user');

        expect($config->password)->toBeNull()
            ->and($config->getProxyUrl())->not->toContain(':@')
            ->and($config->getProxyUrl())->toBe('socks4://user@proxy.local:1080');
    });

    it('proxy URL without credentials contains no auth separator', function () {
        $configs = [
            ProxyConfig::http('h', 80),
            ProxyConfig::socks5('h', 1080),
            ProxyConfig::socks4('h', 1080),
        ];

        foreach ($configs as $config) {
            expect($config->getProxyUrl())->not->toContain('@');
        }
    });

    it('does not allow newline injection in custom headers through HTTP proxy', function () {
        HttpBin::skipIfUnreachable();
        ProxySetup::skipIfUnreachable(ProxySetup::httpHost(), ProxySetup::httpPort());

        $injected = "safe\r\nX-Injected: evil";

        try {
            $response = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->withHeader('X-Test', $injected)
                ->get(HttpBin::proxyUrl('/headers'))
                ->wait();

            $headers = $response->json('headers') ?? [];
            expect(array_key_exists('X-Injected', $headers))->toBeFalse();
        } catch (\Throwable) {
            expect(true)->toBeTrue();
        }
    });

    it('noProxy() request contains no Proxy-Authorization header', function () {
        HttpBin::skipIfUnreachable();

        $response = ProxySetup::client()
            ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort(), 'user', 'pass')
            ->noProxy()
            ->get(HttpBin::url('/headers'))
            ->wait();

        $headers = $response->json('headers') ?? [];

        expect($response->successful())->toBeTrue()
            ->and(array_key_exists('Proxy-Authorization', $headers))->toBeFalse();
    });

    it('noProxy() request contains no Via header injected by proxy', function () {
        HttpBin::skipIfUnreachable();

        $response = ProxySetup::client()
            ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
            ->noProxy()
            ->get(HttpBin::url('/headers'))
            ->wait();

        $headers = $response->json('headers') ?? [];

        expect($response->successful())->toBeTrue()
            ->and(array_key_exists('Via', $headers))->toBeFalse();
    });

    it('proxied request through Squid contains a Via header', function () {
        HttpBin::skipIfUnreachable();
        ProxySetup::skipIfUnreachable(ProxySetup::httpHost(), ProxySetup::httpPort());

        $response = ProxySetup::client()
            ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
            ->get(HttpBin::proxyUrl('/headers'))
            ->wait();

        $headers = $response->json('headers') ?? [];

        expect($response->successful())->toBeTrue()
            ->and(array_key_exists('Via', $headers))->toBeTrue();
    });
});
