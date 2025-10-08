<?php

use Hibla\HttpClient\Cookie;
use Hibla\HttpClient\CookieJar;
use Hibla\HttpClient\FileCookieJar;

describe('Cookie Class Logic', function () {

    test('it can be created and accessors work', function () {
        $expires = time() + 3600;
        $cookie = new Cookie(
            'name',
            'value',
            $expires,
            '.example.com',
            '/path',
            true,
            true,
            3600,
            'Lax'
        );

        expect($cookie->getName())->toBe('name');
        expect($cookie->getValue())->toBe('value');
        expect($cookie->getExpires())->toBe($expires);
        expect($cookie->getDomain())->toBe('.example.com');
        expect($cookie->getPath())->toBe('/path');
        expect($cookie->isSecure())->toBeTrue();
        expect($cookie->isHttpOnly())->toBeTrue();
        expect($cookie->getMaxAge())->toBe(3600);
        expect($cookie->getSameSite())->toBe('Lax');
    });

    test('isExpired correctly checks expiration', function () {
        $notExpired = new Cookie('valid', 'data', time() + 3600);
        $expired = new Cookie('expired', 'data', time() - 3600);
        $session = new Cookie('session', 'data');

        expect($notExpired->isExpired())->toBeFalse();
        expect($expired->isExpired())->toBeTrue();
        expect($session->isExpired())->toBeFalse();
    });

    test('isExpired correctly checks max-age', function () {
        $notExpired = new Cookie('valid', 'data', null, null, null, false, false, 3600);
        $expired = new Cookie('expired', 'data', null, null, null, false, false, 0);
        $expiredNegative = new Cookie('expired-neg', 'data', null, null, null, false, false, -100);

        expect($notExpired->isExpired())->toBeFalse();
        expect($expired->isExpired())->toBeTrue();
        expect($expiredNegative->isExpired())->toBeTrue();
    });

    test('it parses a full Set-Cookie header correctly', function () {
        $header = 'SID=xyz; Expires=Wed, 21 Oct 2025 07:28:00 GMT; Path=/; Domain=.example.com; Secure; HttpOnly; SameSite=Strict';
        $cookie = Cookie::fromSetCookieHeader($header);

        expect($cookie->getName())->toBe('SID');
        expect($cookie->getValue())->toBe('xyz');
        expect($cookie->getExpires())->toBe(strtotime('Wed, 21 Oct 2025 07:28:00 GMT'));
        expect($cookie->getPath())->toBe('/');
        expect($cookie->getDomain())->toBe('.example.com');
        expect($cookie->isSecure())->toBeTrue();
        expect($cookie->isHttpOnly())->toBeTrue();
        expect($cookie->getSameSite())->toBe('Strict');
    });

    dataset('domain_matching', [
        ['.example.com', 'www.example.com', true],
        ['.example.com', 'example.com', true],
        ['example.com', 'example.com', true],
        ['example.com', 'www.example.com', false],
        ['www.example.com', 'example.com', false],
        ['.test.com', 'example.com', false],
    ]);

    test('it matches domains correctly', function ($cookieDomain, $requestDomain, $shouldMatch) {
        $cookie = new Cookie('name', 'value', null, $cookieDomain);
        $matches = $cookie->matches($requestDomain, '/');
        expect($matches)->toBe($shouldMatch);
    })->with('domain_matching');

    dataset('path_matching', [
        ['/', '/any/path', true],
        ['/api', '/api', true],
        ['/api', '/api/v1', true],
        ['/api', '/api/', true],
        ['/api/', '/api/v1', true],
        ['/api', '/apiv1', false],
        ['/api', '/', false],
        ['/api', '/web', false],
    ]);

    test('it matches paths correctly', function ($cookiePath, $requestPath, $shouldMatch) {
        $cookie = new Cookie('name', 'value', null, 'example.com', $cookiePath);
        $matches = $cookie->matches('example.com', $requestPath);
        expect($matches)->toBe($shouldMatch);
    })->with('path_matching');
});

describe('CookieJar (In-Memory)', function () {
    test('it overwrites cookies with the same name, domain, and path', function () {
        $jar = new CookieJar();
        $jar->setCookie(new Cookie('user', 'john', null, 'example.com', '/'));
        $jar->setCookie(new Cookie('user', 'jane', null, 'example.com', '/'));

        $cookies = $jar->getCookies('example.com', '/');
        expect($cookies)->toHaveCount(1);
        expect($cookies[0]->getValue())->toBe('jane');
    });

    test('it does not overwrite cookies with different paths or domains', function () {
        $jar = new CookieJar();
        $jar->setCookie(new Cookie('user', 'john', null, 'example.com', '/'));
        $jar->setCookie(new Cookie('user', 'jane', null, 'example.com', '/api'));
        $jar->setCookie(new Cookie('user', 'jake', null, 'api.example.com', '/'));

        expect($jar->getAllCookies())->toHaveCount(3);
    });

    test('it generates a complex cookie header string', function () {
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

    test('clearExpired removes only the expired cookies', function () {
        $jar = new CookieJar();
        $jar->setCookie(new Cookie('valid', 'data', time() + 3600));
        $jar->setCookie(new Cookie('expired', 'data', time() - 3600));

        $jar->clearExpired();

        expect($jar->getAllCookies())->toHaveCount(1);
        expect($jar->getAllCookies()[0]->getName())->toBe('valid');
    });

    test('clear removes all cookies', function () {
        $jar = new CookieJar();
        $jar->setCookie(new Cookie('c1', 'v1'));
        $jar->setCookie(new Cookie('c2', 'v2'));

        $jar->clear();

        expect($jar->getAllCookies())->toBeEmpty();
    });
});

describe('FileCookieJar (Persistence)', function () {
    $tempFile = '';

    beforeEach(function () use (&$tempFile) {
        $tempFile = tempnam(sys_get_temp_dir(), 'cookie-test-');
    });

    afterEach(function () use (&$tempFile) {
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    });

    test('it saves persistent and session cookies when configured', function () use (&$tempFile) {
        $jar = new FileCookieJar($tempFile, true);
        $jar->setCookie(new Cookie('persistent', 'data1', time() + 3600));
        $jar->setCookie(new Cookie('session', 'data2'));

        unset($jar);

        $newJar = new FileCookieJar($tempFile, true);
        expect($newJar->getAllCookies())->toHaveCount(2);
    });

    test('it does not save session cookies when configured', function () use (&$tempFile) {
        $jar = new FileCookieJar($tempFile, false);
        $jar->setCookie(new Cookie('persistent', 'data1', time() + 3600));
        $jar->setCookie(new Cookie('session', 'data2'));

        unset($jar);

        $newJar = new FileCookieJar($tempFile, false);
        $cookies = $newJar->getAllCookies();

        expect($cookies)->toHaveCount(1);
        expect($cookies[0]->getName())->toBe('persistent');
    });

    test('it loads an empty array from a non-existent file', function () use (&$tempFile) {
        unlink($tempFile);
        $jar = new FileCookieJar($tempFile, true);
        expect($jar->getAllCookies())->toBeEmpty();
    });

    test('clearing the jar also empties the file', function () use (&$tempFile) {
        $jar = new FileCookieJar($tempFile, true);
        $jar->setCookie(new Cookie('test', 'data'));

        $jar->clear();
        unset($jar);

        $fileContents = file_get_contents($tempFile);
        expect(json_decode($fileContents, true))->toBe([]);
    });
});
