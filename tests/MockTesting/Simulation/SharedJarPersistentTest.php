<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Hibla\HttpClient\CookieJar;

use function Hibla\sleep;

beforeEach(function () {
    Http::startTesting();
});

afterEach(function () {
    Http::stopTesting();
});

describe('Shared CookieJar RFC 6265 Scoping in Mocks', function () {

    test('it respects domain suffix matching across different client instances', function () {
        $jar = new CookieJar();

        Http::mock('POST')
            ->url('https://example.com/set')
            ->setCookie('wildcard', 'matched', '/', '.example.com')
            ->register();

        Http::request()->useCookieJar($jar)->post('https://example.com/set')->wait();

        Http::mock('GET')->url('https://api.sub.example.com/get')->register();
        Http::request()->useCookieJar($jar)->get('https://api.sub.example.com/get')->wait();
        Http::assertCookieSent('wildcard');
    });

    test('it prevents cookie leakage across unrelated domains in shared jar', function () {
        $jar = new CookieJar();

        Http::mock('POST')->url('https://site-a.com/set')->setCookie('secret', '123')->register();
        Http::mock('GET')->url('https://site-b.com/get')->register();

        $client = Http::request()->useCookieJar($jar);

        $client->post('https://site-a.com/set')->wait();
        $client->get('https://site-b.com/get')->wait();

        Http::assertCookieNotSentToUrl('secret', 'https://site-b.com/get');
    });

    test('it respects path-level scoping', function () {
        $jar = new CookieJar();

        Http::mock('POST')
            ->url('https://example.com/login')
            ->setCookie('app_session', 'active', '/api')
            ->register();

        Http::mock('GET')->url('https://example.com/api/data')->register();
        Http::mock('GET')->url('https://example.com/public/data')->register();

        $client = Http::request()->useCookieJar($jar);

        $client->post('https://example.com/login')->wait();

        $client->get('https://example.com/api/data')->wait();
        Http::assertCookieSent('app_session');

        $client->get('https://example.com/public/data')->wait();
        Http::assertCookieNotSentToUrl('app_session', 'https://example.com/public/data');
    });

    test('expired cookies in shared jar are not sent by subsequent clients', function () {
        $jar = Http::getTestingHandler()->cookies()->getDefaultCookieJar();

        Http::mock('POST')
            ->url('https://example.com/short-lived')
            ->setCookie('temporary', 'val', '/', null, time() + 1)
            ->register();

        $client = Http::request()->useCookieJar($jar);
        $client->post('https://example.com/short-lived')->wait();

        Http::assertCookieExists('temporary');

        sleep(2);

        Http::mock('GET')->url('https://example.com/check')->register();
        $client->get('https://example.com/check')->wait();

        Http::assertCookieNotSent('temporary');
    });

    test('host-only cookies are not shared with subdomains', function () {
        $jar = new CookieJar();

        Http::mock('POST')
            ->url('https://example.com/set')
            ->setCookie('private', 'data', '/', null)
            ->register();

        Http::request()->useCookieJar($jar)->post('https://example.com/set')->wait();

        Http::mock('GET')->url('https://sub.example.com/get')->register();
        Http::request()->useCookieJar($jar)->get('https://sub.example.com/get')->wait();

        Http::assertCookieNotSent('private');
    });
});
