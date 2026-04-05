<?php

declare(strict_types=1);

use Hibla\HttpClient\CookieJar;
use Hibla\HttpClient\HttpClient;
use Hibla\HttpClient\ValueObjects\Cookie;

use function Hibla\await;

const HTTPBIN = 'https://httpbin.org';

describe("Cookie Handling Real Network Integration Test", function () {

    describe('Manual cookie sending', function () {

        test('a single cookie set via withCookie is received by the server', function () {
            $response = await(
                (new HttpClient())
                    ->withCookie('session', 'abc123')
                    ->get(HTTPBIN . '/cookies')
            );

            expect($response->getStatusCode())->toBe(200);
            expect($response->json('cookies.session'))->toBe('abc123');
        });

        test('multiple cookies set via withCookies are all received by the server', function () {
            $response = await(
                (new HttpClient())
                    ->withCookies(['user' => 'john', 'theme' => 'dark', 'lang' => 'en'])
                    ->get(HTTPBIN . '/cookies')
            );

            $cookies = $response->json('cookies');

            expect($response->getStatusCode())->toBe(200);
            expect($cookies['user'])->toBe('john');
            expect($cookies['theme'])->toBe('dark');
            expect($cookies['lang'])->toBe('en');
        });

        test('withCookie and withCookies can be chained and all cookies are sent', function () {
            $response = await(
                (new HttpClient())
                    ->withCookie('a', '1')
                    ->withCookies(['b' => '2', 'c' => '3'])
                    ->withCookie('d', '4')
                    ->get(HTTPBIN . '/cookies')
            );

            $cookies = $response->json('cookies');

            expect($cookies)->toHaveKey('a');
            expect($cookies)->toHaveKey('b');
            expect($cookies)->toHaveKey('c');
            expect($cookies)->toHaveKey('d');
        });

        test('setting the same cookie name twice keeps only the last value', function () {
            $response = await(
                (new HttpClient())
                    ->withCookie('token', 'first')
                    ->withCookie('token', 'second')
                    ->get(HTTPBIN . '/cookies')
            );

            expect($response->json('cookies.token'))->toBe('second');
        });

        test('values containing special characters must be Base64-encoded before sending', function () {
            $encoded  = base64_encode('hello world');
            $response = await(
                (new HttpClient())
                    ->withCookie('data', $encoded)
                    ->get(HTTPBIN . '/cookies')
            );

            expect($response->json('cookies.data'))->toBe($encoded);
        });

        test('Base64-encoded value round-trips correctly through the server', function () {
            $original = 'user:password123!@#';
            $encoded  = base64_encode($original);

            $response = await(
                (new HttpClient())
                    ->withCookie('creds', $encoded)
                    ->get(HTTPBIN . '/cookies')
            );

            $received = $response->json('cookies.creds');

            expect($received)->toBe($encoded);
            expect(base64_decode($received))->toBe($original);
        });

        test('a DQUOTE-wrapped cookie value is accepted and sent to the server', function () {
            $response = await(
                (new HttpClient())
                    ->withCookie('wrapped', '"quoted-value"')
                    ->get(HTTPBIN . '/cookies')
            );

            // RFC 6265 section 4.1.1 permits DQUOTE-wrapped values as transport-level syntax.
            // Compliant servers may strip the surrounding quotes and store only the inner value,
            // or preserve the quotes as-is. Both are acceptable interpretations.
            $received = $response->json('cookies.wrapped');
            expect($received === '"quoted-value"' || $received === 'quoted-value')->toBeTrue();
        });

        test('cookie value at the boundary of the allowed octet range is accepted', function () {
            // %x21 is '!' (lowest allowed), %x7E is '~' (highest allowed)
            $response = await(
                (new HttpClient())
                    ->withCookie('boundary', '!~')
                    ->get(HTTPBIN . '/cookies')
            );

            expect($response->json('cookies.boundary'))->toBe('!~');
        });

        test('a large number of cookies are all sent correctly', function () {
            $client = new HttpClient();
            $data   = [];

            for ($i = 1; $i <= 20; $i++) {
                $data["cookie{$i}"] = "value{$i}";
                $client = $client->withCookie("cookie{$i}", "value{$i}");
            }

            $cookies = (await($client->get(HTTPBIN . '/cookies')))->json('cookies');

            foreach ($data as $name => $value) {
                expect($cookies)->toHaveKey($name);
                expect($cookies[$name])->toBe($value);
            }
        });
    });

    describe('Cookie validation enforcement', function () {

        test('withCookie rejects values containing spaces', function () {
            expect(fn() => (new HttpClient())->withCookie('data', 'hello world'))
                ->toThrow(\InvalidArgumentException::class, 'cookie-octet set');
        });

        test('withCookie rejects values containing semicolons', function () {
            expect(fn() => (new HttpClient())->withCookie('data', 'a;b'))
                ->toThrow(\InvalidArgumentException::class, 'cookie-octet set');
        });

        test('withCookie rejects values containing commas', function () {
            expect(fn() => (new HttpClient())->withCookie('data', 'a,b'))
                ->toThrow(\InvalidArgumentException::class, 'cookie-octet set');
        });

        test('withCookie rejects an empty cookie name', function () {
            expect(fn() => (new HttpClient())->withCookie('', 'value'))
                ->toThrow(\InvalidArgumentException::class, 'must not be empty');
        });

        test('withCookie rejects a name containing separator characters', function () {
            expect(fn() => (new HttpClient())->withCookie('bad name', 'value'))
                ->toThrow(\InvalidArgumentException::class, 'not permitted in an HTTP token');
        });

        test('withCookie rejects a name containing control characters', function () {
            expect(fn() => (new HttpClient())->withCookie("bad\x01name", 'value'))
                ->toThrow(\InvalidArgumentException::class, 'not permitted in an HTTP token');
        });

        test('withCookies propagates validation exceptions for invalid entries', function () {
            expect(fn() => (new HttpClient())->withCookies(['valid' => 'ok', 'bad name' => 'value']))
                ->toThrow(\InvalidArgumentException::class);
        });
    });

    describe('CookieJar automatic storage and replay', function () {

        test('server-set cookie is stored in the jar after the response', function () {
            $jar = new CookieJar();

            await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->redirects(true)
                    ->get(HTTPBIN . '/cookies/set?token=xyz')
            );

            $cookies = array_values($jar->getCookies('httpbin.org', '/'));

            expect($cookies)->not->toBeEmpty();
            $names = array_map(fn($c) => $c->getName(), $cookies);
            expect($names)->toContain('token');
        });

        test('cookie stored in the jar is replayed on the next request to the same domain', function () {
            $http = new HttpClient();
            $jar  = new CookieJar();

            await(
                $http->useCookieJar($jar)
                    ->redirects(true)
                    ->get(HTTPBIN . '/cookies/set?session=abc')
            );

            $response = await(
                $http->useCookieJar($jar)
                    ->get(HTTPBIN . '/cookies')
            );

            expect($response->json('cookies.session'))->toBe('abc');
        });

        test('multiple server-set cookies are all stored and replayed', function () {
            $http = new HttpClient();
            $jar  = new CookieJar();

            await(
                $http->useCookieJar($jar)
                    ->redirects(true)
                    ->get(HTTPBIN . '/cookies/set?a=1&b=2&c=3')
            );

            $response = await(
                $http->useCookieJar($jar)
                    ->get(HTTPBIN . '/cookies')
            );

            $cookies = $response->json('cookies');

            expect($cookies['a'])->toBe('1');
            expect($cookies['b'])->toBe('2');
            expect($cookies['c'])->toBe('3');
        });

        test('the same jar shared across two HttpClient instances shares cookies between them', function () {
            $jar     = new CookieJar();
            $client1 = (new HttpClient())->useCookieJar($jar)->redirects(true);
            $client2 = (new HttpClient())->useCookieJar($jar);

            await($client1->get(HTTPBIN . '/cookies/set?shared=yes'));

            $response = await($client2->get(HTTPBIN . '/cookies'));

            expect($response->json('cookies.shared'))->toBe('yes');
        });

        test('two separate jars do not share state with each other', function () {
            $jar1 = new CookieJar();
            $jar2 = new CookieJar();

            await(
                (new HttpClient())->useCookieJar($jar1)->redirects(true)
                    ->get(HTTPBIN . '/cookies/set?jar1cookie=yes')
            );

            $response = await(
                (new HttpClient())->useCookieJar($jar2)
                    ->get(HTTPBIN . '/cookies')
            );

            expect($response->json('cookies'))->not->toHaveKey('jar1cookie');
        });

        test('withCookieJar creates a fresh internal jar that is used automatically', function () {
            $http = (new HttpClient())
                ->withCookieJar()
                ->redirects(true);

            await($http->get(HTTPBIN . '/cookies/set?auto=1'));

            $response = await($http->get(HTTPBIN . '/cookies'));

            expect($response->json('cookies.auto'))->toBe('1');
        });

        test('pre-populated jar cookie is sent on the very first request', function () {
            $jar = CookieJar::fromSetCookieHeaders([
                'prefilled=yes; Path=/; Domain=httpbin.org',
            ]);

            $response = await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->get(HTTPBIN . '/cookies')
            );

            expect($response->json('cookies.prefilled'))->toBe('yes');
        });

        test('manual cookie and jar cookie are both sent when both are configured', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'jarCookie',
                value: 'fromjar',
                domain: 'httpbin.org',
                path: '/',
            ));

            $response = await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->withCookie('manualCookie', 'manual')
                    ->get(HTTPBIN . '/cookies')
            );

            $cookies = $response->json('cookies');

            expect($cookies)->toHaveKey('jarCookie');
            expect($cookies)->toHaveKey('manualCookie');
        });
    });

    describe('Cookie deletion via server response', function () {

        test('server-deleted cookie is removed from the jar', function () {
            $http = new HttpClient();
            $jar  = new CookieJar();

            await(
                $http->useCookieJar($jar)
                    ->redirects(true)
                    ->get(HTTPBIN . '/cookies/set?temp=remove_me')
            );

            expect(array_values($jar->getCookies('httpbin.org', '/')))->not->toBeEmpty();

            await(
                $http->useCookieJar($jar)
                    ->redirects(true)
                    ->get(HTTPBIN . '/cookies/delete?temp')
            );

            $response = await(
                $http->useCookieJar($jar)->get(HTTPBIN . '/cookies')
            );

            expect($response->json('cookies'))->not->toHaveKey('temp');
        });

        test('deleting one cookie leaves other cookies in the jar intact', function () {
            $http = new HttpClient();
            $jar  = new CookieJar();

            await(
                $http->useCookieJar($jar)
                    ->redirects(true)
                    ->get(HTTPBIN . '/cookies/set?keep=yes&remove=yes')
            );

            await(
                $http->useCookieJar($jar)
                    ->redirects(true)
                    ->get(HTTPBIN . '/cookies/delete?remove')
            );

            $response = await(
                $http->useCookieJar($jar)->get(HTTPBIN . '/cookies')
            );

            $cookies = $response->json('cookies');

            expect($cookies)->toHaveKey('keep');
            expect($cookies)->not->toHaveKey('remove');
        });
    });

    describe('Secure cookie scheme enforcement', function () {

        test('a Secure cookie stored from an HTTPS response is sent on subsequent HTTPS requests', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'sec',
                value: 'secret',
                domain: 'httpbin.org',
                path: '/',
                secure: true,
            ));

            $response = await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->get('https://httpbin.org/cookies')
            );

            expect($response->json('cookies'))->toHaveKey('sec');
        });

        test('a Secure cookie is not sent when the jar is queried for a non-secure scheme', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'sec',
                value: 'secret',
                domain: 'httpbin.org',
                path: '/',
                secure: true,
            ));

            $header = $jar->getCookieHeader('httpbin.org', '/', isSecure: false);

            expect($header)->toBe('');
            expect($header)->not->toContain('sec=secret');
        });
    });

    describe('Expired cookie enforcement', function () {

        test('an already-expired cookie is not sent to the server', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'stale',
                value: 'old',
                expires: time() - 3600,
                domain: 'httpbin.org',
                path: '/',
            ));

            $response = await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->get(HTTPBIN . '/cookies')
            );

            expect($response->json('cookies'))->not->toHaveKey('stale');
        });

        test('a non-expired cookie is sent while an expired sibling is withheld', function () {
            $jar = new CookieJar();

            $jar->setCookie(new Cookie(
                name: 'alive',
                value: 'yes',
                expires: time() + 3600,
                domain: 'httpbin.org',
                path: '/',
            ));

            $jar->setCookie(new Cookie(
                name: 'dead',
                value: 'no',
                expires: time() - 1,
                domain: 'httpbin.org',
                path: '/',
            ));

            $response = await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->get(HTTPBIN . '/cookies')
            );

            $cookies = $response->json('cookies');

            expect($cookies)->toHaveKey('alive');
            expect($cookies)->not->toHaveKey('dead');
        });

        test('a cookie with max-age=0 is treated as immediately expired and not sent', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'instant',
                value: 'gone',
                domain: 'httpbin.org',
                path: '/',
                maxAge: 0,
            ));

            $response = await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->get(HTTPBIN . '/cookies')
            );

            expect($response->json('cookies'))->not->toHaveKey('instant');
        });
    });

    describe('Cookie path scoping', function () {

        test('a cookie scoped to /cookies is sent to /cookies but withheld from /get', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'scoped',
                value: 'yes',
                domain: 'httpbin.org',
                path: '/cookies',
            ));

            expect($jar->getCookieHeader('httpbin.org', '/cookies'))->toContain('scoped=yes');
            expect($jar->getCookieHeader('httpbin.org', '/get'))->not->toContain('scoped=yes');
        });

        test('a root-scoped cookie is sent to every path on the same domain', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'global',
                value: 'yes',
                domain: 'httpbin.org',
                path: '/',
            ));

            expect($jar->getCookieHeader('httpbin.org', '/'))->toContain('global=yes');
            expect($jar->getCookieHeader('httpbin.org', '/cookies'))->toContain('global=yes');
            expect($jar->getCookieHeader('httpbin.org', '/get'))->toContain('global=yes');
            expect($jar->getCookieHeader('httpbin.org', '/anything/nested/deep'))->toContain('global=yes');
        });

        test('a path-scoped cookie is sent to sub-paths but not sibling paths', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'api',
                value: 'yes',
                domain: 'httpbin.org',
                path: '/anything',
            ));

            expect($jar->getCookieHeader('httpbin.org', '/anything'))->toContain('api=yes');
            expect($jar->getCookieHeader('httpbin.org', '/anything/nested'))->toContain('api=yes');
            expect($jar->getCookieHeader('httpbin.org', '/cookies'))->not->toContain('api=yes');
            expect($jar->getCookieHeader('httpbin.org', '/'))->not->toContain('api=yes');
        });
    });

    describe('cookieWithAttributes manual cookie building', function () {

        test('a cookie built with cookieWithAttributes is sent to the server', function () {
            $response = await(
                (new HttpClient())
                    ->cookieWithAttributes('custom', 'value', [
                        'domain' => 'httpbin.org',
                        'path'   => '/',
                    ])
                    ->get(HTTPBIN . '/cookies')
            );

            expect($response->json('cookies.custom'))->toBe('value');
        });

        test('an expired cookieWithAttributes cookie is not sent to the server', function () {
            $response = await(
                (new HttpClient())
                    ->cookieWithAttributes('stale', 'gone', [
                        'domain'  => 'httpbin.org',
                        'path'    => '/',
                        'expires' => time() - 3600,
                    ])
                    ->get(HTTPBIN . '/cookies')
            );

            expect($response->json('cookies'))->not->toHaveKey('stale');
        });
    });

    describe('clearCookies', function () {

        test('clearCookies prevents previously set manual cookies from being sent', function () {
            $response = await(
                (new HttpClient())
                    ->withCookie('ghost', 'should-not-appear')
                    ->clearCookies()
                    ->get(HTTPBIN . '/cookies')
            );

            expect($response->json('cookies'))->not->toHaveKey('ghost');
        });

        test('cookies added after clearCookies are still sent', function () {
            $response = await(
                (new HttpClient())
                    ->withCookie('before', 'cleared')
                    ->clearCookies()
                    ->withCookie('after', 'kept')
                    ->get(HTTPBIN . '/cookies')
            );

            $cookies = $response->json('cookies');

            expect($cookies)->not->toHaveKey('before');
            expect($cookies)->toHaveKey('after');
        });

        test('clearCookies also clears cookies stored in the jar', function () {
            $jar = new CookieJar();
            $jar->setCookie(new Cookie(
                name: 'persistent',
                value: 'cleared',
                domain: 'httpbin.org',
                path: '/',
            ));

            $response = await(
                (new HttpClient())
                    ->useCookieJar($jar)
                    ->clearCookies()
                    ->get(HTTPBIN . '/cookies')
            );

            expect($response->json('cookies'))->not->toHaveKey('persistent');
            expect($jar->getAllCookies())->toBeEmpty();
        });
    });

    describe('Security', function () {

        describe('Header injection prevention', function () {

            test('a cookie name containing CRLF is rejected before any network call is made', function () {
                expect(fn() => (new HttpClient())->withCookie("inject\r\nX-Injected: evil", 'value'))
                    ->toThrow(\InvalidArgumentException::class, 'not permitted in an HTTP token');
            });

            test('a cookie name containing a lone CR is rejected before any network call is made', function () {
                expect(fn() => (new HttpClient())->withCookie("inject\rX-Injected: evil", 'value'))
                    ->toThrow(\InvalidArgumentException::class, 'not permitted in an HTTP token');
            });

            test('a cookie name containing a lone LF is rejected before any network call is made', function () {
                expect(fn() => (new HttpClient())->withCookie("inject\nX-Injected: evil", 'value'))
                    ->toThrow(\InvalidArgumentException::class, 'not permitted in an HTTP token');
            });

            test('a cookie value containing CRLF is rejected before any network call is made', function () {
                expect(fn() => (new HttpClient())->withCookie('name', "value\r\nX-Injected: evil"))
                    ->toThrow(\InvalidArgumentException::class, 'cookie-octet set');
            });

            test('a cookie value containing a semicolon cannot break out of the cookie field', function () {
                expect(fn() => (new HttpClient())->withCookie('name', 'value; X-Injected: evil'))
                    ->toThrow(\InvalidArgumentException::class, 'cookie-octet set');
            });

            test('a cookie name containing null bytes is rejected', function () {
                expect(fn() => (new HttpClient())->withCookie("name\x00evil", 'value'))
                    ->toThrow(\InvalidArgumentException::class, 'not permitted in an HTTP token');
            });

            test('a cookie value containing null bytes is rejected', function () {
                expect(fn() => (new HttpClient())->withCookie('name', "value\x00evil"))
                    ->toThrow(\InvalidArgumentException::class, 'cookie-octet set');
            });
        });

        describe('Cross-domain isolation', function () {

            test('a cookie scoped to one domain is not sent to a different domain', function () {
                $jar = new CookieJar();
                $jar->setCookie(new Cookie(
                    name: 'secret',
                    value: 'sensitive',
                    domain: 'httpbin.org',
                    path: '/',
                ));

                $header = $jar->getCookieHeader('evil.com', '/');

                expect($header)->toBe('');
                expect($header)->not->toContain('secret=sensitive');
            });

            test('a cookie scoped to a subdomain is not sent to a sibling subdomain', function () {
                $jar = new CookieJar();
                $jar->setCookie(new Cookie(
                    name: 'scoped',
                    value: 'yes',
                    domain: 'api.httpbin.org',
                    path: '/',
                ));

                expect($jar->getCookieHeader('api.httpbin.org', '/'))->toContain('scoped=yes');
                expect($jar->getCookieHeader('other.httpbin.org', '/'))->not->toContain('scoped=yes');
                expect($jar->getCookieHeader('httpbin.org', '/'))->not->toContain('scoped=yes');
            });

            test('a wildcard domain cookie does not leak to a completely different domain', function () {
                $jar = new CookieJar();
                $jar->setCookie(new Cookie(
                    name: 'wide',
                    value: 'yes',
                    domain: '.httpbin.org',
                    path: '/',
                ));

                expect($jar->getCookieHeader('httpbin.org', '/'))->toContain('wide=yes');
                expect($jar->getCookieHeader('sub.httpbin.org', '/'))->toContain('wide=yes');
                expect($jar->getCookieHeader('evil.com', '/'))->not->toContain('wide=yes');
                expect($jar->getCookieHeader('fakehttpbin.org', '/'))->not->toContain('wide=yes');
            });

            test('a cookie sent to the server is not echoed back to a different domain', function () {
                $jar = new CookieJar();

                await(
                    (new HttpClient())
                        ->useCookieJar($jar)
                        ->redirects(true)
                        ->get(HTTPBIN . '/cookies/set?private=yes')
                );

                // Cookie was set for httpbin.org — must not appear for another domain
                $header = $jar->getCookieHeader('attacker.com', '/');

                expect($header)->toBe('');
                expect($header)->not->toContain('private=yes');
            });
        });

        describe('Secure flag enforcement', function () {

            test('a Secure cookie is withheld over HTTP even if the domain matches', function () {
                $jar = new CookieJar();
                $jar->setCookie(new Cookie(
                    name: 'topsecret',
                    value: 'classified',
                    domain: 'httpbin.org',
                    path: '/',
                    secure: true,
                ));

                $header = $jar->getCookieHeader('httpbin.org', '/', isSecure: false);

                expect($header)->toBe('');
                expect($header)->not->toContain('topsecret=classified');
            });

            test('a non-secure cookie is not upgraded to a Secure cookie implicitly', function () {
                $jar = new CookieJar();
                $jar->setCookie(new Cookie(
                    name: 'plain',
                    value: 'data',
                    domain: 'httpbin.org',
                    path: '/',
                    secure: false,
                ));

                // Non-secure cookies are always sent regardless of scheme
                expect($jar->getCookieHeader('httpbin.org', '/', isSecure: false))->toContain('plain=data');
                expect($jar->getCookieHeader('httpbin.org', '/', isSecure: true))->toContain('plain=data');
            });
        });

        describe('Expired cookie enforcement', function () {

            test('an expired cookie is not sent even if the domain and path match exactly', function () {
                $jar = new CookieJar();
                $jar->setCookie(new Cookie(
                    name: 'stale',
                    value: 'leaked',
                    expires: time() - 1,
                    domain: 'httpbin.org',
                    path: '/',
                ));

                $header = $jar->getCookieHeader('httpbin.org', '/');

                expect($header)->toBe('');
                expect($header)->not->toContain('stale=leaked');
            });

            test('a cookie with a negative max-age is treated as immediately expired', function () {
                $jar = new CookieJar();
                $jar->setCookie(new Cookie(
                    name: 'negative',
                    value: 'leaked',
                    domain: 'httpbin.org',
                    path: '/',
                    maxAge: -1,
                ));

                $header = $jar->getCookieHeader('httpbin.org', '/');

                expect($header)->toBe('');
                expect($header)->not->toContain('negative=leaked');
            });
        });
    });
})->skipOnCI();
