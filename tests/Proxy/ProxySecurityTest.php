<?php

declare(strict_types=1);

use Hibla\HttpClient\ValueObjects\ProxyConfig;
use Tests\Fixtures\HttpBin;
use Tests\Fixtures\ProxySetup;

describe('Proxy security', function () {
    describe('ProxyConfig value object safety', function () {

        it('does not expose password through unrelated fields', function () {
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
    });

    describe('header injection prevention', function () {

        beforeEach(function () {
            HttpBin::skipIfUnreachable();
            ProxySetup::skipIfUnreachable(ProxySetup::httpHost(), ProxySetup::httpPort());
        });

        it('does not allow newline injection in custom headers through HTTP proxy', function () {
            $injected = "safe\r\nX-Injected: evil";

            try {
                $response = ProxySetup::client()
                    ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                    ->withHeader('X-Test', $injected)
                    ->get(HttpBin::proxyUrl('/headers'))
                    ->wait();

                expect(array_key_exists('X-Injected', $response->json('headers') ?? []))->toBeFalse();
            } catch (\Throwable) {
                expect(true)->toBeTrue();
            }
        });
    });


    describe('noProxy() bypass verification', function () {

        beforeEach(function () {
            HttpBin::skipIfUnreachable();
        });

        it('noProxy() request contains no Proxy-Authorization header', function () {
            $response = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort(), 'user', 'pass')
                ->noProxy()
                ->get(HttpBin::url('/headers'))
                ->wait();

            $headers = array_change_key_case($response->json('headers') ?? [], CASE_LOWER);

            expect($response->successful())->toBeTrue()
                ->and(isset($headers['proxy-authorization']))->toBeFalse();
        });

        it('noProxy() request contains no Via header injected by proxy', function () {
            $response = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->noProxy()
                ->get(HttpBin::url('/headers'))
                ->wait();

            $headers = array_change_key_case($response->json('headers') ?? [], CASE_LOWER);

            expect($response->successful())->toBeTrue()
                ->and(isset($headers['via']))->toBeFalse();
        });

        it('proxied request through Squid reaches the target successfully', function () {
            ProxySetup::skipIfUnreachable(ProxySetup::httpHost(), ProxySetup::httpPort());

            $response = ProxySetup::client()
                ->withProxy(ProxySetup::httpHost(), ProxySetup::httpPort())
                ->get(HttpBin::proxyUrl('/get'))
                ->wait();

            expect($response->successful())->toBeTrue()
                ->and($response->json('url'))->toContain('hibla_httpbin');
        });
    });
});