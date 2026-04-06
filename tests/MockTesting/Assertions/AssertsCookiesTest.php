<?php

declare(strict_types=1);

use Hibla\HttpClient\HttpClient;
use Hibla\HttpClient\ValueObjects\Cookie;
use PHPUnit\Framework\AssertionFailedError;

describe('AssertsCookies', function () {
    
    test('assertCookieSent validates cookie was sent', function () {
        $handler = testingHttpHandler();

        $jar = $handler->cookies()->createCookieJar();
        $cookie = new Cookie('session', 'abc123', null, 'example.com');
        $jar->setCookie($cookie);

        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->useCookieJar($jar)
            ->get('https://example.com')
            ->wait();

        expect(fn () => $handler->assertCookieSent('session'))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertCookieNotSent validates cookie was not sent', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();

        (new HttpClient())
            ->setHandler($handler)
            ->get('https://example.com')
            ->wait();

        expect(fn () => $handler->assertCookieNotSent('session'))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertCookieSentToUrl and assertCookieNotSentToUrl validate scope routing', function () {
        $handler = testingHttpHandler();
        
        $jar = $handler->cookies()->createCookieJar();
        $jar->setCookie(new Cookie('session', 'abc', null, 'api.example.com'));

        $handler->mock('GET')->url('https://api.example.com/users')->respondWithStatus(200)->register();
        $handler->mock('GET')->url('https://other.com/users')->respondWithStatus(200)->register();

        $client = (new HttpClient())
            ->setHandler($handler)
            ->useCookieJar($jar);

        $client->get('https://api.example.com/users')->wait();
        $client->get('https://other.com/users')->wait();

        expect(fn () => $handler->assertCookieSentToUrl('session', 'https://api.example.com/users'))
            ->not->toThrow(AssertionFailedError::class);

        expect(fn () => $handler->assertCookieNotSentToUrl('session', 'https://other.com/users'))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertCookieExists validates cookie exists in jar', function () {
        $handler = testingHttpHandler();

        $jar = $handler->cookies()->createCookieJar();
        $cookie = new Cookie('session', 'abc123', null, 'example.com');
        $jar->setCookie($cookie);

        $handler->withGlobalCookieJar($jar);

        expect(fn () => $handler->assertCookieExists('session'))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertCookieValue validates cookie value', function () {
        $handler = testingHttpHandler();

        $jar = $handler->cookies()->createCookieJar();
        $cookie = new Cookie('session', 'abc123', null, 'example.com');
        $jar->setCookie($cookie);

        $handler->withGlobalCookieJar($jar);

        expect(fn () => $handler->assertCookieValue('session', 'abc123'))
            ->not->toThrow(AssertionFailedError::class);
    });

    test('assertCookieHasAttributes validates deep cookie configuration', function () {
        $handler = testingHttpHandler();
        $jar = $handler->cookies()->createCookieJar();
        
        $cookie = new Cookie(
            name: 'session',
            value: 'xyz',
            expires: 1700000000,
            domain: 'example.com',
            path: '/admin',
            secure: true,
            httpOnly: true,
            sameSite: 'Strict'
        );
        $jar->setCookie($cookie);
        $handler->withGlobalCookieJar($jar);

        expect(fn () => $handler->assertCookieHasAttributes('session', [
            'value'    => 'xyz',
            'domain'   => 'example.com',
            'path'     => '/admin',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
            'expires'  => 1700000000
        ]))->not->toThrow(AssertionFailedError::class);

        expect(fn () => $handler->assertCookieHasAttributes('session', ['secure' => false]))
            ->toThrow(AssertionFailedError::class);
    });

    test('assertCookieExpired and assertCookieNotExpired validate temporal state', function () {
        $handler = testingHttpHandler();
        $jar = $handler->cookies()->createCookieJar();

        $expired = new Cookie('old_cookie', '123', time() - 3600);
        $active  = new Cookie('new_cookie', '123', time() + 3600);

        $jar->setCookie($expired);
        $jar->setCookie($active);
        $handler->withGlobalCookieJar($jar);

        expect(fn () => $handler->assertCookieExpired('old_cookie'))
            ->not->toThrow(AssertionFailedError::class);

        expect(fn () => $handler->assertCookieNotExpired('new_cookie'))
            ->not->toThrow(AssertionFailedError::class);
            
        expect(fn () => $handler->assertCookieExpired('new_cookie'))
            ->toThrow(AssertionFailedError::class);
    });

    test('boolean flag assertions validate Secure, HttpOnly, and HostOnly states', function () {
        $handler = testingHttpHandler();
        $jar = $handler->cookies()->createCookieJar();

        $secureHttpOnly = new Cookie(
            name: 'safe_cookie',
            value: '123',
            secure: true,
            httpOnly: true,
            hostOnly: false
        );

        $hostOnly = new Cookie(
            name: 'host_cookie',
            value: '123',
            domain: 'example.com',
            hostOnly: true
        );

        $jar->setCookie($secureHttpOnly);
        $jar->setCookie($hostOnly);
        $handler->withGlobalCookieJar($jar);

        expect(fn () => $handler->assertCookieIsSecure('safe_cookie'))
            ->not->toThrow(AssertionFailedError::class);

        expect(fn () => $handler->assertCookieIsHttpOnly('safe_cookie'))
            ->not->toThrow(AssertionFailedError::class);

        expect(fn () => $handler->assertCookieIsHostOnly('host_cookie'))
            ->not->toThrow(AssertionFailedError::class);

        expect(fn () => $handler->assertCookieIsHostOnly('safe_cookie'))
            ->toThrow(AssertionFailedError::class);
            
        expect(fn () => $handler->assertCookieIsSecure('host_cookie'))
            ->toThrow(AssertionFailedError::class);
    });

});