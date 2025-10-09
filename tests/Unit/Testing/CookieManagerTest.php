<?php

use Hibla\HttpClient\Cookie;
use Hibla\HttpClient\CookieJar;
use Hibla\HttpClient\FileCookieJar;
use Hibla\HttpClient\Testing\Exceptions\MockAssertionException;
use Hibla\HttpClient\Testing\MockedRequest;

describe('CookieManager', function () {
    test('can create in-memory cookie jar', function () {
        $cookieManager = createCookieManager();

        $jar = $cookieManager->createCookieJar('test');

        expect($jar)->toBeInstanceOf(CookieJar::class)
            ->and($cookieManager->getCookieJar('test'))->toBe($jar)
        ;

        $cookieManager->cleanup();
    });

    test('can create file-based cookie jar', function () {
        $cookieManager = createCookieManager();
        $filename = sys_get_temp_dir().'/test_cookies_'.uniqid().'.json';

        $jar = $cookieManager->createFileCookieJar($filename, true, 'file_jar');

        expect($jar)->toBeInstanceOf(FileCookieJar::class)
            ->and($cookieManager->getCookieJar('file_jar'))->toBe($jar)
        ;

        $cookieManager->cleanup();
    });

    test('sets first created jar as default', function () {
        $cookieManager = createCookieManager();

        $jar = $cookieManager->createCookieJar('first');

        expect($cookieManager->getDefaultCookieJar())->toBe($jar);

        $cookieManager->cleanup();
    });

    test('can set custom default cookie jar', function () {
        $cookieManager = createCookieManager();

        $jar1 = $cookieManager->createCookieJar('jar1');
        $jar2 = $cookieManager->createCookieJar('jar2');

        $cookieManager->setDefaultCookieJar($jar2);

        expect($cookieManager->getDefaultCookieJar())->toBe($jar2);

        $cookieManager->cleanup();
    });

    test('creates default jar automatically when accessed', function () {
        $cookieManager = createCookieManager();

        $jar = $cookieManager->getDefaultCookieJar();

        expect($jar)->toBeInstanceOf(CookieJar::class);

        $cookieManager->cleanup();
    });

    test('can add simple cookie', function () {
        $cookieManager = createCookieManager();

        $cookieManager->addCookie('session_id', 'abc123');

        $jar = $cookieManager->getDefaultCookieJar();
        $cookies = $jar->getAllCookies();

        expect($cookies)->toHaveCount(1)
            ->and($cookies[0]->getName())->toBe('session_id')
            ->and($cookies[0]->getValue())->toBe('abc123')
        ;

        $cookieManager->cleanup();
    });

    test('can add cookie with full attributes', function () {
        $cookieManager = createCookieManager();
        $expires = time() + 3600;

        $cookieManager->addCookie(
            name: 'auth_token',
            value: 'token123',
            domain: 'example.com',
            path: '/api',
            expires: $expires,
            secure: true,
            httpOnly: true,
            sameSite: 'Strict'
        );

        $jar = $cookieManager->getDefaultCookieJar();
        $cookies = $jar->getAllCookies();

        expect($cookies)->toHaveCount(1)
            ->and($cookies[0]->getName())->toBe('auth_token')
            ->and($cookies[0]->getValue())->toBe('token123')
            ->and($cookies[0]->getDomain())->toBe('example.com')
            ->and($cookies[0]->getPath())->toBe('/api')
            ->and($cookies[0]->getExpires())->toBe($expires)
            ->and($cookies[0]->isSecure())->toBeTrue()
            ->and($cookies[0]->isHttpOnly())->toBeTrue()
            ->and($cookies[0]->getSameSite())->toBe('Strict')
        ;

        $cookieManager->cleanup();
    });

    test('can add multiple cookies at once with string values', function () {
        $cookieManager = createCookieManager();

        $cookieManager->addCookies([
            'cookie1' => 'value1',
            'cookie2' => 'value2',
            'cookie3' => 'value3',
        ]);

        expect($cookieManager->getCookieCount())->toBe(3);

        $cookieManager->cleanup();
    });

    test('can add multiple cookies with array configs', function () {
        $cookieManager = createCookieManager();

        $cookieManager->addCookies([
            'session' => [
                'value' => 'sess123',
                'domain' => 'example.com',
                'secure' => true,
            ],
            'preference' => [
                'value' => 'dark_mode',
                'path' => '/settings',
            ],
        ]);

        expect($cookieManager->getCookieCount())->toBe(2);

        $cookieManager->cleanup();
    });

    test('can mock set cookies on MockedRequest', function () {
        $cookieManager = createCookieManager();
        $mock = new MockedRequest();

        $cookieManager->mockSetCookies($mock, [
            'simple' => 'value1',
            'complex' => [
                'value' => 'value2',
                'domain' => 'example.com',
                'path' => '/api',
                'secure' => true,
                'httpOnly' => true,
                'sameSite' => 'Lax',
            ],
        ]);

        $headers = $mock->getHeaders();

        expect($headers)->toHaveKey('Set-Cookie')
            ->and($headers['Set-Cookie'])->toBeArray()
            ->and($headers['Set-Cookie'])->toHaveCount(2)
        ;
    });

    test('mockSetCookies includes expires header', function () {
        $cookieManager = createCookieManager();
        $mock = new MockedRequest();
        $expires = time() + 3600;

        $cookieManager->mockSetCookies($mock, [
            'expiring' => [
                'value' => 'test',
                'expires' => $expires,
            ],
        ]);

        $headers = $mock->getHeaders();
        $setCookie = $headers['Set-Cookie'];

        expect($setCookie)->toContain('Expires=');
    });

    test('can assert cookie exists', function () {
        $cookieManager = createCookieManager();

        $cookieManager->addCookie('test_cookie', 'test_value');

        expect(fn () => $cookieManager->assertCookieExists('test_cookie'))
            ->not->toThrow(MockAssertionException::class)
        ;

        $cookieManager->cleanup();
    });

    test('assert cookie exists throws when cookie not found', function () {
        $cookieManager = createCookieManager();

        // Create the default jar first so it exists
        $cookieManager->createCookieJar('default');

        expect(fn () => $cookieManager->assertCookieExists('non_existent'))
            ->toThrow(MockAssertionException::class, "Cookie 'non_existent' not found")
        ;

        $cookieManager->cleanup();
    });

    test('assert cookie exists throws when jar not found', function () {
        $cookieManager = createCookieManager();

        expect(fn () => $cookieManager->assertCookieExists('test', 'invalid_jar'))
            ->toThrow(MockAssertionException::class, "Cookie jar 'invalid_jar' not found")
        ;

        $cookieManager->cleanup();
    });

    test('can assert cookie value', function () {
        $cookieManager = createCookieManager();

        $cookieManager->addCookie('test_cookie', 'expected_value');

        expect(fn () => $cookieManager->assertCookieValue('test_cookie', 'expected_value'))
            ->not->toThrow(MockAssertionException::class)
        ;

        $cookieManager->cleanup();
    });

    test('assert cookie value throws when value mismatches', function () {
        $cookieManager = createCookieManager();

        $cookieManager->addCookie('test_cookie', 'actual_value');

        expect(fn () => $cookieManager->assertCookieValue('test_cookie', 'expected_value'))
            ->toThrow(MockAssertionException::class, "has value 'actual_value', expected 'expected_value'")
        ;

        $cookieManager->cleanup();
    });

    test('can assert cookie was sent in request', function () {
        $cookieManager = createCookieManager();

        $curlOptions = [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Cookie: session_id=abc123; auth_token=xyz789',
            ],
        ];

        expect(fn () => $cookieManager->assertCookieSent('session_id', $curlOptions))
            ->not->toThrow(MockAssertionException::class)
        ;
    });

    test('assert cookie sent throws when no cookie header', function () {
        $cookieManager = createCookieManager();

        $curlOptions = [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
        ];

        expect(fn () => $cookieManager->assertCookieSent('session_id', $curlOptions))
            ->toThrow(MockAssertionException::class, 'No Cookie header found')
        ;
    });

    test('assert cookie sent throws when cookie not in header', function () {
        $cookieManager = createCookieManager();

        $curlOptions = [
            CURLOPT_HTTPHEADER => [
                'Cookie: other_cookie=value',
            ],
        ];

        expect(fn () => $cookieManager->assertCookieSent('session_id', $curlOptions))
            ->toThrow(MockAssertionException::class, "Cookie 'session_id' was not sent")
        ;
    });

    test('can get cookie count', function () {
        $cookieManager = createCookieManager();

        $cookieManager->addCookie('cookie1', 'value1');
        $cookieManager->addCookie('cookie2', 'value2');

        expect($cookieManager->getCookieCount())->toBe(2);

        $cookieManager->cleanup();
    });

    test('cookie count returns zero for non-existent jar', function () {
        $cookieManager = createCookieManager();

        expect($cookieManager->getCookieCount('non_existent'))->toBe(0);

        $cookieManager->cleanup();
    });

    test('can clear cookies from jar', function () {
        $cookieManager = createCookieManager();

        $cookieManager->addCookie('cookie1', 'value1');
        $cookieManager->addCookie('cookie2', 'value2');

        expect($cookieManager->getCookieCount())->toBe(2);

        $cookieManager->clearCookies();

        expect($cookieManager->getCookieCount())->toBe(0);

        $cookieManager->cleanup();
    });

    test('can apply cookies to curl options', function () {
        $cookieManager = createCookieManager();

        $cookieManager->addCookie('test_cookie', 'test_value', 'example.com');

        $curlOptions = [];
        $cookieManager->applyCookiesToCurlOptions($curlOptions, 'https://example.com/api');

        expect($curlOptions)->toHaveKey(CURLOPT_HTTPHEADER)
            ->and($curlOptions[CURLOPT_HTTPHEADER])->toContain('Cookie: test_cookie=test_value')
        ;

        $cookieManager->cleanup();
    });

    test('applies cookies to existing cookie header', function () {
        $cookieManager = createCookieManager();

        $cookieManager->addCookie('new_cookie', 'new_value', 'example.com');

        $curlOptions = [
            CURLOPT_HTTPHEADER => [
                'Cookie: existing_cookie=existing_value',
            ],
        ];

        $cookieManager->applyCookiesToCurlOptions($curlOptions, 'https://example.com/api');

        $cookieHeader = $curlOptions[CURLOPT_HTTPHEADER][0];

        expect($cookieHeader)->toContain('existing_cookie=existing_value')
            ->and($cookieHeader)->toContain('new_cookie=new_value')
        ;

        $cookieManager->cleanup();
    });

    test('can process Set-Cookie headers from response', function () {
        $cookieManager = createCookieManager();

        // Create the default jar first
        $cookieManager->createCookieJar('default');

        $headers = [
            'Set-Cookie' => [
                'session_id=abc123; Path=/; HttpOnly',
                'user_pref=dark_mode; Path=/settings',
            ],
        ];

        $cookieManager->processSetCookieHeaders($headers);

        expect($cookieManager->getCookieCount())->toBe(2);

        $cookieManager->cleanup();
    });

    test('processes single Set-Cookie header', function () {
        $cookieManager = createCookieManager();

        // Create the default jar first
        $cookieManager->createCookieJar('default');

        $headers = [
            'Set-Cookie' => 'single_cookie=value; Path=/',
        ];

        $cookieManager->processSetCookieHeaders($headers);

        expect($cookieManager->getCookieCount())->toBe(1);

        $cookieManager->cleanup();
    });

    test('can create temporary cookie file', function () {
        $cookieManager = createCookieManager();

        $filename = $cookieManager->createTempCookieFile();

        expect($filename)->toBeString()
            ->and(str_contains($filename, 'test_cookies_'))->toBeTrue()
        ;

        $cookieManager->cleanup();
    });

    test('cleanup removes created files', function () {
        $cookieManager = createCookieManager();

        $filename = $cookieManager->createTempCookieFile();
        file_put_contents($filename, 'test');

        expect(file_exists($filename))->toBeTrue();

        $cookieManager->cleanup();

        expect(file_exists($filename))->toBeFalse();
    });

    test('cleanup clears all jars', function () {
        $cookieManager = createCookieManager();

        $cookieManager->createCookieJar('jar1');
        $cookieManager->createCookieJar('jar2');

        expect($cookieManager->getCookieJar('jar1'))->not->toBeNull();

        $cookieManager->cleanup();

        expect($cookieManager->getCookieJar('jar1'))->toBeNull()
            ->and($cookieManager->getCookieJar('jar2'))->toBeNull()
        ;
    });

    test('can get debug info', function () {
        $cookieManager = createCookieManager();

        $cookieManager->createCookieJar('test_jar');
        $cookieManager->addCookie('test_cookie', 'test_value', jarName: 'test_jar');

        $debug = $cookieManager->getDebugInfo();

        expect($debug)->toHaveKey('test_jar')
            ->and($debug['test_jar'])->toHaveKeys(['type', 'cookie_count', 'cookies'])
            ->and($debug['test_jar']['type'])->toBe('memory')
            ->and($debug['test_jar']['cookie_count'])->toBe(1)
            ->and($debug['test_jar']['cookies'][0]['name'])->toBe('test_cookie')
        ;

        $cookieManager->cleanup();
    });

    test('debug info shows file type for FileCookieJar', function () {
        $cookieManager = createCookieManager();
        $filename = sys_get_temp_dir().'/test_'.uniqid().'.json';

        $cookieManager->createFileCookieJar($filename, true, 'file_jar');

        $debug = $cookieManager->getDebugInfo();

        expect($debug['file_jar']['type'])->toBe('file');

        $cookieManager->cleanup();
    });

    test('can apply cookies from custom jar in options', function () {
        $cookieManager = createCookieManager();
        $customJar = new CookieJar();
        $cookie = new Cookie('custom_cookie', 'custom_value', null, 'example.com', '/');
        $customJar->setCookie($cookie);

        $curlOptions = [
            '_cookie_jar' => $customJar,
        ];

        $cookieManager->applyCookiesForRequestOptions($curlOptions, 'https://example.com/api');

        expect($curlOptions[CURLOPT_HTTPHEADER])->toContain('Cookie: custom_cookie=custom_value');

        $cookieManager->cleanup();
    });

    test('can apply cookies from named jar in options', function () {
        $cookieManager = createCookieManager();

        $cookieManager->createCookieJar('custom');
        $cookieManager->addCookie('named_cookie', 'named_value', 'example.com', jarName: 'custom');

        $curlOptions = [
            '_cookie_jar' => 'custom',
        ];

        $cookieManager->applyCookiesForRequestOptions($curlOptions, 'https://example.com/api');

        expect($curlOptions[CURLOPT_HTTPHEADER])->toContain('Cookie: named_cookie=named_value');

        $cookieManager->cleanup();
    });

    test('processes response cookies for custom jar', function () {
        $cookieManager = createCookieManager();
        $customJar = new CookieJar();
        $curlOptions = [
            '_cookie_jar' => $customJar,
        ];

        $headers = [
            'Set-Cookie' => 'response_cookie=response_value; Path=/',
        ];

        $cookieManager->processResponseCookiesForOptions($headers, $curlOptions, 'https://example.com');

        $cookies = $customJar->getAllCookies();

        expect($cookies)->toHaveCount(1)
            ->and($cookies[0]->getName())->toBe('response_cookie')
        ;

        $cookieManager->cleanup();
    });

    test('sets domain when processing cookies without domain', function () {
        $cookieManager = createCookieManager();
        $customJar = new CookieJar();
        $curlOptions = [
            '_cookie_jar' => $customJar,
        ];

        $headers = [
            'Set-Cookie' => 'no_domain_cookie=value; Path=/',
        ];

        $cookieManager->processResponseCookiesForOptions($headers, $curlOptions, 'https://example.com/test');

        $cookies = $customJar->getAllCookies();

        expect($cookies[0]->getDomain())->toBe('example.com');

        $cookieManager->cleanup();
    });

    test('auto manage can be disabled', function () {
        $manager = createCookieManager(autoManage: false);
        $filename = $manager->createTempCookieFile();
        file_put_contents($filename, 'test');

        expect(file_exists($filename))->toBeTrue();

        $manager->cleanup();

        expect(file_exists($filename))->toBeTrue();

        // Manual cleanup
        unlink($filename);
    });
});
