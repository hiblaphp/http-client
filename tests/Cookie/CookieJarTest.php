<?php

declare(strict_types=1);

use Hibla\HttpClient\CookieJar;
use Hibla\HttpClient\ValueObjects\Cookie;

describe('CookieJar', function () {

    describe('setCookie / storage', function () {

        test('RFC 6265 section 5.3 - overwrites cookies with the same name, domain, and path', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie('user', 'john', null, 'example.com', '/'));
            $jar->setCookie(new Cookie('user', 'jane', null, 'example.com', '/'));

            $cookies = array_values($jar->getCookies('example.com', '/'));
            expect($cookies)->toHaveCount(1);
            expect($cookies[0]->getValue())->toBe('jane');
        });

        test('RFC 6265 section 5.3 - does not overwrite cookies with different paths or domains', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie('user', 'john', null, 'example.com', '/'));
            $jar->setCookie(new Cookie('user', 'jane', null, 'example.com', '/api'));
            $jar->setCookie(new Cookie('user', 'jake', null, 'api.example.com', '/'));

            expect($jar->getAllCookies())->toHaveCount(3);
        });

        test('RFC 6265 section 4.2.2 - cookies with the same name but different domains are stored and matched independently', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie('token', 'root', null, 'example.com', '/'));
            $jar->setCookie(new Cookie('token', 'api', null, 'api.example.com', '/'));

            $cookies = array_values($jar->getCookies('api.example.com', '/'));
            expect($cookies)->toHaveCount(1);
            expect($cookies[0]->getValue())->toBe('api');
        });

    });

    describe('getCookieHeader', function () {

        test('RFC 6265 section 5.4 - generates a correct Cookie header for a given domain, path, and scheme', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie('c1', 'v1', null, 'example.com', '/'));
            $jar->setCookie(new Cookie('c2', 'v2', null, '.example.com', '/api'));
            $jar->setCookie(new Cookie('c3', 'v3-secure', null, 'api.example.com', '/', true));
            $jar->setCookie(new Cookie('c4', 'v4-other-domain', null, 'google.com', '/'));

            $header = $jar->getCookieHeader('api.example.com', '/api/v1', true);

            expect($header)->not->toContain('c1=v1');
            expect($header)->toContain('c2=v2');
            expect($header)->toContain('c3=v3-secure');
            expect($header)->not->toContain('c4=v4-other-domain');
        });

        test('RFC 6265 section 5.4 - expired cookies are excluded from the Cookie header', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie('alive', 'yes', time() + 3600, 'example.com', '/'));
            $jar->setCookie(new Cookie('dead', 'no', time() - 1, 'example.com', '/'));

            $header = $jar->getCookieHeader('example.com', '/');
            expect($header)->toContain('alive=yes');
            expect($header)->not->toContain('dead=no');
        });

        test('RFC 6265 section 5.4 - returns an empty string when no cookies match the request', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie('c1', 'v1', null, 'example.com', '/'));

            expect($jar->getCookieHeader('other.com', '/'))->toBe('');
        });

        test('RFC 6265 section 5.4 - secure cookies are excluded when the request is not over HTTPS', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie('pub', 'visible', null, 'example.com', '/'));
            $jar->setCookie(new Cookie('priv', 'hidden', null, 'example.com', '/', true));

            $header = $jar->getCookieHeader('example.com', '/', isSecure: false);
            expect($header)->toContain('pub=visible');
            expect($header)->not->toContain('priv=hidden');
        });

        test('RFC 6265 section 4.1.2.3 - a wildcard domain cookie is sent to the bare domain and all its subdomains', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie('global', 'yes', null, '.example.com', '/'));

            expect($jar->getCookieHeader('example.com', '/'))->toContain('global=yes');
            expect($jar->getCookieHeader('www.example.com', '/'))->toContain('global=yes');
            expect($jar->getCookieHeader('api.example.com', '/'))->toContain('global=yes');
            expect($jar->getCookieHeader('other.com', '/'))->toBe('');
        });

    });

    describe('clearExpired / clear', function () {

        test('RFC 6265 section 5.3 - clearExpired removes only the expired cookies', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie('valid', 'data', time() + 3600));
            $jar->setCookie(new Cookie('expired', 'data', time() - 3600));

            $jar->clearExpired();

            $remaining = array_values($jar->getAllCookies());
            expect($remaining)->toHaveCount(1);
            expect($remaining[0]->getName())->toBe('valid');
        });

        test('RFC 6265 section 5.3 - clear removes all cookies from the store', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie('c1', 'v1'));
            $jar->setCookie(new Cookie('c2', 'v2'));

            $jar->clear();

            expect($jar->getAllCookies())->toBeEmpty();
        });

    });

    describe('CookieJar::fromSetCookieHeaders', function () {

        test('RFC 6265 section 5.2 - populates the jar correctly from valid Set-Cookie headers', function () {
            $jar = CookieJar::fromSetCookieHeaders([
                'session=abc; Path=/; Domain=example.com; HttpOnly',
                'lang=en; Path=/; Domain=example.com',
            ]);

            $cookies = array_values($jar->getCookies('example.com', '/'));
            expect($cookies)->toHaveCount(2);
        });

        test('RFC 6265 section 5.2 - silently skips invalid Set-Cookie headers', function () {
            $jar = CookieJar::fromSetCookieHeaders([
                'valid=yes; Path=/; Domain=example.com',
                '=nope; Path=/',
                'badformat',
                "ctl\x01bad=value",
                'bad name=value; Path=/',
                'name=bad value; Path=/',
            ]);

            expect($jar->getAllCookies())->toHaveCount(1);
            expect(array_values($jar->getAllCookies())[0]->getName())->toBe('valid');
        });

    });

});
