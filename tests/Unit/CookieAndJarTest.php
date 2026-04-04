<?php

declare(strict_types=1);

use Hibla\HttpClient\CookieJar;
use Hibla\HttpClient\ValueObjects\Cookie;

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

    test('RFC 6265 section 5.1.4 - getPath returns / when no path attribute was provided', function () {
        $cookie = new Cookie('name', 'value');
        expect($cookie->getPath())->toBe('/');
    });

    test('RFC 6265 section 4.1.2.1 - isExpired correctly checks expiration via Expires attribute', function () {
        $notExpired = new Cookie('valid', 'data', time() + 3600);
        $expired    = new Cookie('expired', 'data', time() - 3600);
        $session    = new Cookie('session', 'data');

        expect($notExpired->isExpired())->toBeFalse();
        expect($expired->isExpired())->toBeTrue();
        expect($session->isExpired())->toBeFalse();
    });

    test('RFC 6265 section 4.1.2.2 - isExpired treats max-age as a relative duration in seconds from time of receipt', function () {
        $notExpired      = new Cookie('valid', 'data', null, null, null, false, false, 3600);
        $expiredZero     = new Cookie('expired-zero', 'data', null, null, null, false, false, 0);
        $expiredNegative = new Cookie('expired-neg', 'data', null, null, null, false, false, -100);

        expect($notExpired->isExpired())->toBeFalse();
        expect($expiredZero->isExpired())->toBeTrue();
        expect($expiredNegative->isExpired())->toBeTrue();
    });

    test('RFC 6265 section 4.1.2.2 - max-age takes precedence over expires when both attributes are present', function () {
        $cookie = new Cookie(
            name: 'dual',
            value: 'data',
            expires: time() - 3600,
            maxAge: 3600
        );

        expect($cookie->isExpired())->toBeFalse();
    });

    test('RFC 6265 section 4.1.2.2 - max-age=0 expires the cookie even when expires is in the future', function () {
        $cookie = new Cookie(
            name: 'dual',
            value: 'data',
            expires: time() + 9999,
            maxAge: 0
        );

        expect($cookie->isExpired())->toBeTrue();
    });

    test('RFC 6265 section 4.1.1 - it parses a full Set-Cookie header correctly', function () {
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

    test('RFC 6265 section 4.1.1 - it URL-decodes the cookie value on parse', function () {
        $cookie = Cookie::fromSetCookieHeader('session=hello%20world; Path=/');

        expect($cookie)->not->toBeNull();
        expect($cookie->getValue())->toBe('hello world');
    });

    test('RFC 6265 section 4.1.1 - it parses a cookie value that contains equals signs such as base64 tokens', function () {
        $cookie = Cookie::fromSetCookieHeader('token=abc==; Path=/');

        expect($cookie)->not->toBeNull();
        expect($cookie->getName())->toBe('token');
        expect($cookie->getValue())->toBe('abc==');
    });

    test('RFC 6265 section 5.2 - it handles case-insensitive attribute names', function () {
        $cookie = Cookie::fromSetCookieHeader('id=1; SECURE; HTTPONLY; SAMESITE=Lax; MAX-AGE=600; DOMAIN=example.com; PATH=/api');

        expect($cookie)->not->toBeNull();
        expect($cookie->isSecure())->toBeTrue();
        expect($cookie->isHttpOnly())->toBeTrue();
        expect($cookie->getSameSite())->toBe('Lax');
        expect($cookie->getMaxAge())->toBe(600);
        expect($cookie->getDomain())->toBe('example.com');
        expect($cookie->getPath())->toBe('/api');
    });

    test('RFC 6265 section 4.1.2 - it ignores unrecognised extension attributes without failing', function () {
        $cookie = Cookie::fromSetCookieHeader('id=1; Path=/; UnknownAttr=whatever; AnotherUnknown');

        expect($cookie)->not->toBeNull();
        expect($cookie->getName())->toBe('id');
    });

    test('RFC 6265 section 4.1.1 - it rejects a Set-Cookie header with an empty cookie name', function () {
        $cookie = Cookie::fromSetCookieHeader('=somevalue; Path=/');
        expect($cookie)->toBeNull();
    });

    test('RFC 6265 section 4.1.1 - it rejects a Set-Cookie header missing an equals sign', function () {
        $cookie = Cookie::fromSetCookieHeader('justanamenovalue');
        expect($cookie)->toBeNull();
    });

    test('RFC 6265bis section 5.2 - it rejects a Set-Cookie header containing CTL characters', function () {
        $cookie = Cookie::fromSetCookieHeader("bad\x01name=value");
        expect($cookie)->toBeNull();
    });

    test('RFC 6265bis section 5.2 - it allows HTAB in a Set-Cookie header as HTAB is excluded from CTL rejection', function () {
        $cookie = Cookie::fromSetCookieHeader("name=val\x09ue");
        expect($cookie)->not->toBeNull();
        expect($cookie->getName())->toBe('name');
    });

    test('RFC 6265 section 4.1.1 - toSetCookieHeader serializes all attributes in the correct format', function () {
        $expires = mktime(7, 28, 0, 10, 21, 2025);
        $cookie  = new Cookie('SID', 'xyz', $expires, '.example.com', '/', true, true, null, 'Lax');
        $header  = $cookie->toSetCookieHeader();

        expect($header)->toStartWith('SID=');
        expect($header)->toContain('Domain=.example.com');
        expect($header)->toContain('Path=/');
        expect($header)->toContain('Secure');
        expect($header)->toContain('HttpOnly');
        expect($header)->toContain('SameSite=Lax');
        expect($header)->toContain('Expires=');
    });

    test('RFC 6265 section 4.1.1 - toSetCookieHeader omits optional attributes when not set', function () {
        $cookie = new Cookie('simple', 'value');
        $header = $cookie->toSetCookieHeader();

        expect($header)->toBe('simple=value');
        expect($header)->not->toContain('Domain');
        expect($header)->not->toContain('Path');
        expect($header)->not->toContain('Expires');
        expect($header)->not->toContain('Max-Age');
        expect($header)->not->toContain('Secure');
        expect($header)->not->toContain('HttpOnly');
        expect($header)->not->toContain('SameSite');
    });

    test('RFC 6265 section 4.1.1 - toSetCookieHeader URL-encodes the cookie value', function () {
        $cookie = new Cookie('msg', 'hello world');
        expect($cookie->toSetCookieHeader())->toContain('msg=hello+world');
    });

    test('RFC 6265 section 4.2.1 - __toString returns the Cookie header representation containing only name and value', function () {
        $cookie = new Cookie('SID', 'xyz', time() + 3600, '.example.com', '/', true, true);
        expect((string) $cookie)->toBe('SID=xyz');
    });

    test('RFC 6265 section 4.1.2.5 - a secure cookie is not sent over a non-secure connection', function () {
        $cookie = new Cookie('token', 'secret', null, 'example.com', '/', true);

        expect($cookie->matches('example.com', '/', isSecure: false))->toBeFalse();
        expect($cookie->matches('example.com', '/', isSecure: true))->toBeTrue();
    });

    test('RFC 6265 section 4.1.2.5 - a non-secure cookie is sent over both secure and non-secure connections', function () {
        $cookie = new Cookie('pref', 'dark', null, 'example.com', '/');

        expect($cookie->matches('example.com', '/', isSecure: false))->toBeTrue();
        expect($cookie->matches('example.com', '/', isSecure: true))->toBeTrue();
    });

    dataset('domain_matching', [
        'RFC 6265 section 5.1.3 - leading dot enables subdomain matching'
            => ['.example.com', 'www.example.com', true],
        'RFC 6265 section 5.1.3 - leading dot also matches the bare domain exactly'
            => ['.example.com', 'example.com', true],
        'RFC 6265 section 5.1.3 - exact match without leading dot'
            => ['example.com', 'example.com', true],
        'RFC 6265 section 5.1.3 - no subdomain matching when domain has no leading dot'
            => ['example.com', 'www.example.com', false],
        'RFC 6265 section 5.1.3 - shorter request domain must not match longer cookie domain'
            => ['www.example.com', 'example.com', false],
        'RFC 6265 section 5.1.3 - completely different domain does not match'
            => ['.test.com', 'example.com', false],
        'RFC 6265 section 5.1.3 - domain comparison is case-insensitive uppercase cookie domain'
            => ['.EXAMPLE.COM', 'www.example.com', true],
        'RFC 6265 section 5.1.3 - domain comparison is case-insensitive mixed-case request domain'
            => ['Example.Com', 'example.com', true],
        'RFC 6265 section 5.1.3 - suffix match does not apply to raw IPv4 addresses'
            => ['.1.1', '192.168.1.1', false],
        'RFC 6265 section 5.1.3 - suffix match does not apply to raw IPv6 addresses'
            => ['.1', '::1', false],
        'RFC 6265 section 5.1.3 - partial string match without a dot boundary is rejected'
            => ['.example.com', 'notexample.com', false],
    ]);

    test('it matches domains correctly', function ($cookieDomain, $requestDomain, $shouldMatch) {
        $cookie  = new Cookie('name', 'value', null, $cookieDomain);
        $matches = $cookie->matches($requestDomain, '/');
        expect($matches)->toBe($shouldMatch);
    })->with('domain_matching');

    dataset('path_matching', [
        'RFC 6265 section 5.1.4 - root path matches any request path'
            => ['/', '/any/path', true],
        'RFC 6265 section 5.1.4 - exact path match'
            => ['/api', '/api', true],
        'RFC 6265 section 5.1.4 - cookie path is a prefix of the request path separated by a slash'
            => ['/api', '/api/v1', true],
        'RFC 6265 section 5.1.4 - cookie path prefix match when request path has trailing slash'
            => ['/api', '/api/', true],
        'RFC 6265 section 5.1.4 - cookie path with trailing slash matches subdirectory'
            => ['/api/', '/api/v1', true],
        'RFC 6265 section 5.1.4 - prefix match requires a slash boundary not just a string prefix'
            => ['/api', '/apiv1', false],
        'RFC 6265 section 5.1.4 - deeper cookie path does not match shorter request path'
            => ['/api', '/', false],
        'RFC 6265 section 5.1.4 - cookie path does not match a completely different path'
            => ['/api', '/web', false],
    ]);

    test('it matches paths correctly', function ($cookiePath, $requestPath, $shouldMatch) {
        $cookie  = new Cookie('name', 'value', null, 'example.com', $cookiePath);
        $matches = $cookie->matches('example.com', $requestPath);
        expect($matches)->toBe($shouldMatch);
    })->with('path_matching');

    test('RFC 6265 section 5.1.4 - a null path matches any request path', function () {
        $cookie = new Cookie('name', 'value', null, 'example.com', null);

        expect($cookie->matches('example.com', '/'))->toBeTrue();
        expect($cookie->matches('example.com', '/deep/nested/path'))->toBeTrue();
    });
});

describe('CookieJar (In-Memory)', function () {

    test('RFC 6265 section 5.3 - it overwrites cookies with the same name domain and path', function () {
        $jar = new CookieJar();
        $jar->setCookie(new Cookie('user', 'john', null, 'example.com', '/'));
        $jar->setCookie(new Cookie('user', 'jane', null, 'example.com', '/'));

        $cookies = array_values($jar->getCookies('example.com', '/'));
        expect($cookies)->toHaveCount(1);
        expect($cookies[0]->getValue())->toBe('jane');
    });

    test('RFC 6265 section 5.3 - it does not overwrite cookies with different paths or domains', function () {
        $jar = new CookieJar();
        $jar->setCookie(new Cookie('user', 'john', null, 'example.com', '/'));
        $jar->setCookie(new Cookie('user', 'jane', null, 'example.com', '/api'));
        $jar->setCookie(new Cookie('user', 'jake', null, 'api.example.com', '/'));

        expect($jar->getAllCookies())->toHaveCount(3);
    });

    test('RFC 6265 section 5.4 - it generates a correct cookie header for a given domain path and scheme', function () {
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

    test('RFC 6265 section 5.4 - expired cookies are excluded from getCookieHeader', function () {
        $jar = new CookieJar();
        $jar->setCookie(new Cookie('alive', 'yes', time() + 3600, 'example.com', '/'));
        $jar->setCookie(new Cookie('dead', 'no', time() - 1, 'example.com', '/'));

        $header = $jar->getCookieHeader('example.com', '/');
        expect($header)->toContain('alive=yes');
        expect($header)->not->toContain('dead=no');
    });

    test('RFC 6265 section 5.4 - getCookieHeader returns empty string when no cookies match the request', function () {
        $jar = new CookieJar();
        $jar->setCookie(new Cookie('c1', 'v1', null, 'example.com', '/'));

        expect($jar->getCookieHeader('other.com', '/'))->toBe('');
    });

    test('RFC 6265 section 5.2 - fromSetCookieHeaders factory populates the jar correctly', function () {
        $jar = CookieJar::fromSetCookieHeaders([
            'session=abc; Path=/; Domain=example.com; HttpOnly',
            'lang=en; Path=/; Domain=example.com',
        ]);

        $cookies = array_values($jar->getCookies('example.com', '/'));
        expect($cookies)->toHaveCount(2);
    });

    test('RFC 6265 section 5.2 - fromSetCookieHeaders silently skips invalid Set-Cookie headers', function () {
        $jar = CookieJar::fromSetCookieHeaders([
            'valid=yes; Path=/; Domain=example.com',
            '=nope; Path=/',
            'badformat',
            "ctl\x01bad=value",
        ]);

        expect($jar->getAllCookies())->toHaveCount(1);
        expect(array_values($jar->getAllCookies())[0]->getName())->toBe('valid');
    });

    test('RFC 6265 section 4.2.2 - cookies with the same name but different domains are both stored and independently matched', function () {
        $jar = new CookieJar();
        $jar->setCookie(new Cookie('token', 'root', null, 'example.com', '/'));
        $jar->setCookie(new Cookie('token', 'api', null, 'api.example.com', '/'));

        $cookies = array_values($jar->getCookies('api.example.com', '/'));
        expect($cookies)->toHaveCount(1);
        expect($cookies[0]->getValue())->toBe('api');
    });

    test('RFC 6265 section 4.1.2.3 - a wildcard domain cookie is sent to the bare domain and all its subdomains', function () {
        $jar = new CookieJar();
        $jar->setCookie(new Cookie('global', 'yes', null, '.example.com', '/'));

        expect($jar->getCookieHeader('example.com', '/'))->toContain('global=yes');
        expect($jar->getCookieHeader('www.example.com', '/'))->toContain('global=yes');
        expect($jar->getCookieHeader('api.example.com', '/'))->toContain('global=yes');
        expect($jar->getCookieHeader('other.com', '/'))->toBe('');
    });

    test('RFC 6265 section 5.4 - secure cookies are excluded from the cookie header when the request is not over HTTPS', function () {
        $jar = new CookieJar();
        $jar->setCookie(new Cookie('pub', 'visible', null, 'example.com', '/'));
        $jar->setCookie(new Cookie('priv', 'hidden', null, 'example.com', '/', true));

        $header = $jar->getCookieHeader('example.com', '/', isSecure: false);
        expect($header)->toContain('pub=visible');
        expect($header)->not->toContain('priv=hidden');
    });
});
