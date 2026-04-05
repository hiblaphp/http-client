<?php

declare(strict_types=1);

use Hibla\HttpClient\ValueObjects\Cookie;

describe('Cookie accessors', function () {

    test('it can be created and all accessors return the correct values', function () {
        $expires = time() + 3600;
        $cookie  = new Cookie(
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
});

describe('Cookie::isValidName', function () {

    test('returns true for a valid token name', function () {
        expect(Cookie::isValidName('session'))->toBeTrue();
        expect(Cookie::isValidName('session_id'))->toBeTrue();
        expect(Cookie::isValidName('X-Token'))->toBeTrue();
    });

    test('returns false for an empty name', function () {
        expect(Cookie::isValidName(''))->toBeFalse();
    });

    test('returns false for names containing HTTP separator characters', function () {
        foreach (['na me', "na\tme", 'na;me', 'na,me', 'na=me', 'na(me', 'na)me', 'na/me'] as $name) {
            expect(Cookie::isValidName($name))->toBeFalse();
        }
    });

    test('returns false for names containing control characters', function () {
        expect(Cookie::isValidName("na\x01me"))->toBeFalse();
        expect(Cookie::isValidName("na\x1Fme"))->toBeFalse();
        expect(Cookie::isValidName("na\x7Fme"))->toBeFalse();
    });

    test('returns false for names containing non-ASCII bytes', function () {
        expect(Cookie::isValidName("na\x80me"))->toBeFalse();
        expect(Cookie::isValidName("na\xFFme"))->toBeFalse();
    });
});

describe('Cookie::isValidValue', function () {

    test('returns true for a valid cookie-octet value', function () {
        expect(Cookie::isValidValue('abc123'))->toBeTrue();
        expect(Cookie::isValidValue('abc-123'))->toBeTrue();
        expect(Cookie::isValidValue(''))->toBeTrue();
    });

    test('returns true for a DQUOTE-wrapped value', function () {
        expect(Cookie::isValidValue('"abc123"'))->toBeTrue();
    });

    test('returns true for a Base64-encoded value', function () {
        expect(Cookie::isValidValue(base64_encode('arbitrary data')))->toBeTrue();
    });

    test('returns false for values containing a space', function () {
        expect(Cookie::isValidValue('hello world'))->toBeFalse();
    });

    test('returns false for values containing a semicolon', function () {
        expect(Cookie::isValidValue('a;b'))->toBeFalse();
    });

    test('returns false for values containing a comma', function () {
        expect(Cookie::isValidValue('a,b'))->toBeFalse();
    });

    test('returns false for values containing a backslash', function () {
        expect(Cookie::isValidValue('a\\b'))->toBeFalse();
    });

    test('returns false for values containing control characters', function () {
        expect(Cookie::isValidValue("a\x01b"))->toBeFalse();
        expect(Cookie::isValidValue("a\x7Fb"))->toBeFalse();
    });
});

describe('Cookie::assertValidName', function () {

    test('does not throw for a valid name', function () {
        expect(fn() => Cookie::assertValidName('session'))->not->toThrow(\InvalidArgumentException::class);
    });

    test('throws with an empty-name message for an empty string', function () {
        expect(fn() => Cookie::assertValidName(''))
            ->toThrow(\InvalidArgumentException::class, 'must not be empty');
    });

    test('throws with an invalid-token message for a name with separator characters', function () {
        expect(fn() => Cookie::assertValidName('bad name'))
            ->toThrow(\InvalidArgumentException::class, 'not permitted in an HTTP token');
    });
});

describe('Cookie::assertValidValue', function () {

    test('does not throw for a valid cookie-octet value', function () {
        expect(fn() => Cookie::assertValidValue('abc123'))->not->toThrow(\InvalidArgumentException::class);
    });

    test('does not throw for an empty value', function () {
        expect(fn() => Cookie::assertValidValue(''))->not->toThrow(\InvalidArgumentException::class);
    });

    test('throws for a value containing characters outside the cookie-octet set', function () {
        expect(fn() => Cookie::assertValidValue('bad value'))
            ->toThrow(\InvalidArgumentException::class, 'cookie-octet set');
    });

    test('throws and suggests Base64 encoding for arbitrary data', function () {
        expect(fn() => Cookie::assertValidValue('hello world'))
            ->toThrow(\InvalidArgumentException::class, 'base64_encode');
    });
});

describe('Cookie expiration', function () {

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
            maxAge: 3600,
        );

        expect($cookie->isExpired())->toBeFalse();
    });

    test('RFC 6265 section 4.1.2.2 - max-age=0 expires the cookie even when expires is in the future', function () {
        $cookie = new Cookie(
            name: 'dual',
            value: 'data',
            expires: time() + 9999,
            maxAge: 0,
        );

        expect($cookie->isExpired())->toBeTrue();
    });
});

describe('Cookie::fromSetCookieHeader', function () {

    test('RFC 6265 section 4.1.1 - parses a full Set-Cookie header correctly', function () {
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

    test('RFC 6265 section 4.1.1 - URL-decodes the cookie value on parse', function () {
        $cookie = Cookie::fromSetCookieHeader('session=hello%20world; Path=/');

        expect($cookie)->not->toBeNull();
        expect($cookie->getValue())->toBe('hello world');
    });

    test('RFC 6265 section 4.1.1 - parses a cookie value that contains equals signs such as Base64 tokens', function () {
        $cookie = Cookie::fromSetCookieHeader('token=abc==; Path=/');

        expect($cookie)->not->toBeNull();
        expect($cookie->getName())->toBe('token');
        expect($cookie->getValue())->toBe('abc==');
    });

    test('RFC 6265 section 5.2 - handles case-insensitive attribute names', function () {
        $cookie = Cookie::fromSetCookieHeader('id=1; SECURE; HTTPONLY; SAMESITE=Lax; MAX-AGE=600; DOMAIN=example.com; PATH=/api');

        expect($cookie)->not->toBeNull();
        expect($cookie->isSecure())->toBeTrue();
        expect($cookie->isHttpOnly())->toBeTrue();
        expect($cookie->getSameSite())->toBe('Lax');
        expect($cookie->getMaxAge())->toBe(600);
        expect($cookie->getDomain())->toBe('example.com');
        expect($cookie->getPath())->toBe('/api');
    });

    test('RFC 6265 section 4.1.2 - ignores unrecognised extension attributes without failing', function () {
        $cookie = Cookie::fromSetCookieHeader('id=1; Path=/; UnknownAttr=whatever; AnotherUnknown');

        expect($cookie)->not->toBeNull();
        expect($cookie->getName())->toBe('id');
    });

    test('RFC 6265 section 4.1.1 - rejects a Set-Cookie header with an empty cookie name', function () {
        expect(Cookie::fromSetCookieHeader('=somevalue; Path=/'))->toBeNull();
    });

    test('RFC 6265 section 4.1.1 - rejects a Set-Cookie header missing an equals sign', function () {
        expect(Cookie::fromSetCookieHeader('justanamenovalue'))->toBeNull();
    });

    test('RFC 6265 section 4.1.1 - rejects a name containing invalid token characters', function () {
        expect(Cookie::fromSetCookieHeader('bad name=value; Path=/'))->toBeNull();
    });

    test('RFC 6265 section 4.1.1 - rejects a value containing characters outside the cookie-octet set', function () {
        expect(Cookie::fromSetCookieHeader('name=bad value; Path=/'))->toBeNull();
    });

    test('defensive - rejects a Set-Cookie header containing CTL characters to prevent malformed input', function () {
        expect(Cookie::fromSetCookieHeader("bad\x01name=value"))->toBeNull();
    });

    test('defensive - HTAB is excluded from CTL rejection but is still outside the cookie-octet set', function () {
        // The CTL filter (\x00-\x08\x0A-\x1F\x7F) explicitly skips HTAB,
        // but HTAB is not a valid cookie-octet, so the cookie is still rejected
        // by value validation rather than the CTL check.
        $cookie = Cookie::fromSetCookieHeader("name=val\x09ue");
        expect($cookie)->toBeNull();
    });
});

describe('Cookie serialization', function () {

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
        $header = (new Cookie('simple', 'value'))->toSetCookieHeader();

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
});

describe('Cookie::matches', function () {

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
        $cookie = new Cookie('name', 'value', null, $cookieDomain);
        expect($cookie->matches($requestDomain, '/'))->toBe($shouldMatch);
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
        $cookie = new Cookie('name', 'value', null, 'example.com', $cookiePath);
        expect($cookie->matches('example.com', $requestPath))->toBe($shouldMatch);
    })->with('path_matching');

    test('RFC 6265 section 5.1.4 - a null path matches any request path', function () {
        $cookie = new Cookie('name', 'value', null, 'example.com', null);

        expect($cookie->matches('example.com', '/'))->toBeTrue();
        expect($cookie->matches('example.com', '/deep/nested/path'))->toBeTrue();
    });
});
