<?php

use Hibla\HttpClient\Cookie;
use PHPUnit\Framework\AssertionFailedError;

describe('AssertsCookies', function () {
    test('assertCookieSent validates cookie was sent', function () {
        $handler = testingHttpHandler();

        $jar = $handler->cookies()->createCookieJar();
        $cookie = new Cookie('session', 'abc123', null, 'example.com');
        $jar->setCookie($cookie);

        $handler->mock('GET')->url('https://example.com')->respondWithStatus(200)->register();
        $handler->fetch('https://example.com', ['cookie_jar' => $jar])->await();

        expect(fn () => $handler->assertCookieSent('session'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertCookieExists validates cookie exists in jar', function () {
        $handler = testingHttpHandler();

        $jar = $handler->cookies()->createCookieJar();
        $cookie = new Cookie('session', 'abc123', null, 'example.com');
        $jar->setCookie($cookie);

        $handler->withGlobalCookieJar($jar);

        expect(fn () => $handler->assertCookieExists('session'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });

    test('assertCookieValue validates cookie value', function () {
        $handler = testingHttpHandler();

        $jar = $handler->cookies()->createCookieJar();
        $cookie = new Cookie('session', 'abc123', null, 'example.com');
        $jar->setCookie($cookie);

        $handler->withGlobalCookieJar($jar);

        expect(fn () => $handler->assertCookieValue('session', 'abc123'))
            ->not->toThrow(AssertionFailedError::class)
        ;
    });
});
