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

    test('it does not send secure cookies over non-https requests', function () {
        $jar = Http::getTestingHandler()->cookies()->getDefaultCookieJar();
        
        Http::mock('POST')
            ->url('https://secure.com/set')
            ->setCookie('secret_key', 'vault-value', '/', null, null, true) 
            ->register();

        Http::request()->useCookieJar($jar)->post('https://secure.com/set')->wait();

        Http::mock('GET')->url('http://secure.com/get')->register();
        Http::request()->useCookieJar($jar)->get('http://secure.com/get')->wait();
        Http::assertCookieNotSent('secret_key');
    });

    test('it captures multiple Set-Cookie headers from a single response', function () {
        $jar = Http::getTestingHandler()->cookies()->getDefaultCookieJar();
        
        Http::mock('GET')
            ->url('https://example.com/multi-cookie')
            ->respondWithHeaders([
                'Set-Cookie' => [
                    'theme=dark; Path=/',
                    'layout=compact; Path=/',
                    'tracking=false; Domain=.example.com'
                ]
            ])
            ->register();

        Http::request()->useCookieJar($jar)->get('https://example.com/multi-cookie')->wait();

        Http::assertCookieValue('theme', 'dark');
        Http::assertCookieValue('layout', 'compact');
        Http::assertCookieValue('tracking', 'false');
    });

    test('it merges manually added request cookies with the shared cookie jar', function () {
        $jar = Http::getTestingHandler()->cookies()->getDefaultCookieJar();
        
        Http::mock('POST')->url('https://app.com/login')->setCookie('sess_id', 'abc-123')->register();
        Http::request()->useCookieJar($jar)->post('https://app.com/login')->wait();

        Http::mock('GET')->url('https://app.com/api/data')->register();
        Http::request()
            ->useCookieJar($jar)
            ->withCookie('client_side_pref', 'large_font')
            ->get('https://app.com/api/data')
            ->wait();

        Http::assertCookieSent('sess_id');
        Http::assertCookieSent('client_side_pref');
    });

    test('it respects case-insensitivity for domain matching', function () {
        $jar = Http::getTestingHandler()->cookies()->getDefaultCookieJar();
        
        Http::mock('POST')->url('https://example.com/set')->setCookie('case', 'insensitive')->register();
        Http::request()->useCookieJar($jar)->post('https://example.com/set')->wait();
        Http::mock('GET')->url('https://EXAMPLE.COM/get')->register();
        Http::request()->useCookieJar($jar)->get('https://EXAMPLE.COM/get')->wait();

        Http::assertCookieSent('case');
    });

    test('it overwrites existing cookies when name, domain, and path match', function () {
        $jar = Http::getTestingHandler()->cookies()->getDefaultCookieJar();
        
        Http::mock('GET')->url('https://api.com/v1')->setCookie('api_token', 'old-token')->register();
        Http::mock('GET')->url('https://api.com/v2')->setCookie('api_token', 'new-token')->register();

        $client = Http::request()->useCookieJar($jar);
        
        $client->get('https://api.com/v1')->wait();
        Http::assertCookieValue('api_token', 'old-token');

        $client->get('https://api.com/v2')->wait();
        Http::assertCookieValue('api_token', 'new-token');
        
        expect($jar->getAllCookies())->toHaveCount(1);
    });

    test('it does not suffix match IP addresses', function () {
        $jar = Http::getTestingHandler()->cookies()->getDefaultCookieJar();
        
        Http::mock('POST')
            ->url('https://127.0.0.1/set')
            ->setCookie('ip_cookie', 'strict', '/', '127.0.0.1')
            ->register();

        Http::request()->useCookieJar($jar)->post('https://127.0.0.1/set')->wait();

        Http::mock('GET')->url('https://localhost/get')->register();
        Http::request()->useCookieJar($jar)->get('https://localhost/get')->wait();

        Http::assertCookieNotSent('ip_cookie');
    });
});
