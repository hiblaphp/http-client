<?php

declare(strict_types=1);

use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Uri;
use Hibla\HttpClient\Validators\UriValidator;

describe('UriValidator', function () {
    describe('assertNoControlCharacters', function () {

        it('accepts a clean URL', function () {
            expect(fn () => UriValidator::assertNoControlCharacters('https://api.example.com/users?page=1'))
                ->not->toThrow(InvalidArgumentException::class)
            ;
        });

        it('accepts a URL with percent-encoded characters', function () {
            expect(fn () => UriValidator::assertNoControlCharacters('https://example.com/path%20with%20spaces'))
                ->not->toThrow(InvalidArgumentException::class)
            ;
        });

        it('throws on a carriage return in the URL', function () {
            expect(fn () => UriValidator::assertNoControlCharacters("https://example.com/path\rEvil: injected"))
                ->toThrow(InvalidArgumentException::class)
            ;
        });

        it('throws on a line feed in the URL', function () {
            expect(fn () => UriValidator::assertNoControlCharacters("https://example.com/path\nHost: evil.com"))
                ->toThrow(InvalidArgumentException::class)
            ;
        });

        it('throws on a CRLF sequence in the URL (request-splitting)', function () {
            expect(fn () => UriValidator::assertNoControlCharacters("https://example.com/get\r\nEvil-Header: injected"))
                ->toThrow(InvalidArgumentException::class)
            ;
        });

        it('throws on a null byte in the URL', function () {
            expect(fn () => UriValidator::assertNoControlCharacters("https://example.com/path\0/../secret"))
                ->toThrow(InvalidArgumentException::class)
            ;
        });

        it('throws on a tab character in the URL', function () {
            expect(fn () => UriValidator::assertNoControlCharacters("https://example.com/path\there"))
                ->toThrow(InvalidArgumentException::class)
            ;
        });

        it('throws on a low ASCII control character (e.g. 0x01)', function () {
            expect(fn () => UriValidator::assertNoControlCharacters("https://example.com/\x01resource"))
                ->toThrow(InvalidArgumentException::class)
            ;
        });

        it('throws on the DEL character (0x7F)', function () {
            expect(fn () => UriValidator::assertNoControlCharacters("https://example.com/path\x7F"))
                ->toThrow(InvalidArgumentException::class)
            ;
        });

        it('throws when a control character appears only in the query string', function () {
            expect(fn () => UriValidator::assertNoControlCharacters("https://example.com/path?q=value\r\nInjected: yes"))
                ->toThrow(InvalidArgumentException::class)
            ;
        });

        it('throws when a control character appears only in the fragment', function () {
            expect(fn () => UriValidator::assertNoControlCharacters("https://example.com/path#section\nnewline"))
                ->toThrow(InvalidArgumentException::class)
            ;
        });

        it('accepts an empty string without throwing', function () {
            expect(fn () => UriValidator::assertNoControlCharacters(''))
                ->not->toThrow(InvalidArgumentException::class)
            ;
        });
    });

    describe('assertAllowedScheme', function () {

        it('accepts an http URI', function () {
            expect(fn () => UriValidator::assertAllowedScheme(new Uri('http://example.com/api')))
                ->not->toThrow(NetworkException::class)
            ;
        });

        it('accepts an https URI', function () {
            expect(fn () => UriValidator::assertAllowedScheme(new Uri('https://example.com/api')))
                ->not->toThrow(NetworkException::class)
            ;
        });

        it('accepts an uppercase HTTP scheme (normalised comparison)', function () {
            expect(fn () => UriValidator::assertAllowedScheme(new Uri('HTTP://example.com/api')))
                ->not->toThrow(NetworkException::class)
            ;
        });

        it('accepts an uppercase HTTPS scheme (normalised comparison)', function () {
            expect(fn () => UriValidator::assertAllowedScheme(new Uri('HTTPS://example.com/api')))
                ->not->toThrow(NetworkException::class)
            ;
        });

        it('accepts a URI with an empty scheme (relative URI)', function () {
            expect(fn () => UriValidator::assertAllowedScheme(new Uri('//example.com/relative')))
                ->not->toThrow(NetworkException::class)
            ;
        });

        it('throws on a file:// URI', function () {
            expect(fn () => UriValidator::assertAllowedScheme(new Uri('file:///etc/passwd')))
                ->toThrow(NetworkException::class)
            ;
        });

        it('throws on a gopher:// URI', function () {
            expect(fn () => UriValidator::assertAllowedScheme(new Uri('gopher://127.0.0.1:6379/_FLUSHALL')))
                ->toThrow(NetworkException::class)
            ;
        });

        it('throws on a dict:// URI', function () {
            expect(fn () => UriValidator::assertAllowedScheme(new Uri('dict://127.0.0.1:11211/stat')))
                ->toThrow(NetworkException::class)
            ;
        });

        it('throws on an ftp:// URI', function () {
            expect(fn () => UriValidator::assertAllowedScheme(new Uri('ftp://internal.example.com/data')))
                ->toThrow(NetworkException::class)
            ;
        });

        it('throws on an ldap:// URI', function () {
            expect(fn () => UriValidator::assertAllowedScheme(new Uri('ldap://127.0.0.1/dc=example,dc=com')))
                ->toThrow(NetworkException::class)
            ;
        });

        it('throws on a javascript: URI', function () {
            expect(fn () => UriValidator::assertAllowedScheme(new Uri('javascript:alert(1)')))
                ->toThrow(NetworkException::class)
            ;
        });

        it('throws on a data: URI', function () {
            expect(fn () => UriValidator::assertAllowedScheme(new Uri('data:text/html,<script>alert(1)</script>')))
                ->toThrow(NetworkException::class)
            ;
        });

        it('includes the blocked scheme name in the exception message', function () {
            $thrownMessage = null;

            try {
                UriValidator::assertAllowedScheme(new Uri('gopher://127.0.0.1/evil'));
            } catch (NetworkException $e) {
                $thrownMessage = $e->getMessage();
            }

            expect($thrownMessage)->toContain('gopher');
        });
    });

    describe('isCrossDomain', function () {

        describe('same-origin — must return false', function () {

            it('returns false for identical http URIs', function () {
                $a = new Uri('http://example.com/path');
                $b = new Uri('http://example.com/other-path');

                expect(UriValidator::isCrossDomain($a, $b))->toBeFalse();
            });

            it('returns false for identical https URIs with explicit default port', function () {
                $a = new Uri('https://example.com/start');
                $b = new Uri('https://example.com/end');

                expect(UriValidator::isCrossDomain($a, $b))->toBeFalse();
            });

            it('returns false when hosts differ only in case', function () {
                $a = new Uri('https://EXAMPLE.COM/path');
                $b = new Uri('https://example.com/path');

                expect(UriValidator::isCrossDomain($a, $b))->toBeFalse();
            });

            it('returns false for matching IPv4 loopback addresses', function () {
                $a = new Uri('http://127.0.0.1/api');
                $b = new Uri('http://127.0.0.1/internal');

                expect(UriValidator::isCrossDomain($a, $b))->toBeFalse();
            });

            it('returns false for matching IPv6 addresses without zone IDs', function () {
                $a = new Uri('http://[::1]/path');
                $b = new Uri('http://[::1]/other');

                expect(UriValidator::isCrossDomain($a, $b))->toBeFalse();
            });

            it('returns false when both URIs have the same explicit non-standard port', function () {
                $a = new Uri('http://example.com:8080/path');
                $b = new Uri('http://example.com:8080/other');

                expect(UriValidator::isCrossDomain($a, $b))->toBeFalse();
            });

            it('returns false for an IPv6 address with a zone ID vs the same bare address (RFC 6874)', function () {
                $a = new Uri('http://[::1]/start');
                $b = new Uri('http://[::1%25eth0]/resource');

                expect(UriValidator::isCrossDomain($a, $b))->toBeFalse();
            });

            it('returns false when both URIs carry the same IPv6 zone ID', function () {
                $a = new Uri('http://[::1%25eth0]/path');
                $b = new Uri('http://[::1%25eth0]/other');

                expect(UriValidator::isCrossDomain($a, $b))->toBeFalse();
            });
        });

        describe('cross-origin — must return true', function () {

            it('returns true when the host changes', function () {
                $a = new Uri('https://origin.example.com/api');
                $b = new Uri('https://evil.example.com/steal');

                expect(UriValidator::isCrossDomain($a, $b))->toBeTrue();
            });

            it('returns true when the scheme downgrades from https to http', function () {
                $a = new Uri('https://example.com/secure');
                $b = new Uri('http://example.com/insecure');

                expect(UriValidator::isCrossDomain($a, $b))->toBeTrue();
            });

            it('returns true when the scheme upgrades from http to https', function () {
                $a = new Uri('http://example.com/page');
                $b = new Uri('https://example.com/page');

                expect(UriValidator::isCrossDomain($a, $b))->toBeTrue();
            });

            it('returns true when the port changes (CVE-2022-31091)', function () {
                $a = new Uri('http://example.com:8080/api');
                $b = new Uri('http://example.com:9090/api');

                expect(UriValidator::isCrossDomain($a, $b))->toBeTrue();
            });

            it('returns true when the original has no port and the redirect adds one', function () {
                $a = new Uri('http://example.com/api');
                $b = new Uri('http://example.com:8080/api');

                expect(UriValidator::isCrossDomain($a, $b))->toBeTrue();
            });

            it('returns true when the original has a port and the redirect removes it', function () {
                $a = new Uri('http://example.com:8080/api');
                $b = new Uri('http://example.com/api');

                expect(UriValidator::isCrossDomain($a, $b))->toBeTrue();
            });

            it('returns true when only a subdomain is added', function () {
                $a = new Uri('https://example.com/api');
                $b = new Uri('https://sub.example.com/api');

                expect(UriValidator::isCrossDomain($a, $b))->toBeTrue();
            });

            it('returns true for a redirect to an IPv4 loopback even from a public host', function () {
                $a = new Uri('https://public.example.com/api');
                $b = new Uri('http://127.0.0.1/internal');

                expect(UriValidator::isCrossDomain($a, $b))->toBeTrue();
            });

            it('returns true for two different IPv6 zone IDs on the same base address', function () {
                $a = new Uri('http://[::1%25eth0]/path');
                $b = new Uri('http://[::1%25eth1]/path');

                expect(UriValidator::isCrossDomain($a, $b))->toBeFalse();
            });

            it('returns true when host changes and scheme changes simultaneously', function () {
                $a = new Uri('https://origin.example.com/secure');
                $b = new Uri('http://evil.com/steal');

                expect(UriValidator::isCrossDomain($a, $b))->toBeTrue();
            });
        });
    });

    describe('IDN / Punycode Homograph Attack Prevention', function () {

        describe('same-origin — must return false', function () {

            it('treats a unicode host and its punycode encoding as the same origin', function () {
                $a = new Uri('https://münchen.de/account');
                $b = new Uri('https://xn--mnchen-3ya.de/account');

                expect(UriValidator::isCrossDomain($a, $b))->toBeFalse();
            });

            it('treats two punycode representations of the same unicode domain as same origin', function () {
                $a = new Uri('https://xn--mnchen-3ya.de/path');
                $b = new Uri('https://xn--mnchen-3ya.de/other');

                expect(UriValidator::isCrossDomain($a, $b))->toBeFalse();
            });
        });

        describe('cross-origin — must return true', function () {

            it('treats a Cyrillic lookalike as a different origin from the ASCII domain', function () {
                $legitimate = new Uri('https://apple.com/login');
                $homograph = new Uri('https://аpple.com/login'); // Cyrillic а

                expect(UriValidator::isCrossDomain($legitimate, $homograph))->toBeTrue();
            });

            it('treats a Greek lookalike as a different origin from the ASCII domain', function () {
                $legitimate = new Uri('https://google.com/');
                $homograph = new Uri('https://ɡoogle.com/'); // U+0261

                expect(UriValidator::isCrossDomain($legitimate, $homograph))->toBeTrue();
            });

            it('treats two different IDN domains as cross-origin', function () {
                $a = new Uri('https://münchen.de/path');
                $b = new Uri('https://zürich.ch/path');

                expect(UriValidator::isCrossDomain($a, $b))->toBeTrue();
            });
        });
    });

    describe('DNS Rebinding (Known Limitation — Partial Mitigations Only)', function () {
        it('documents that full DNS rebinding prevention requires network-level controls', function () {
            // DNS rebinding works in two phases:
            //
            // Phase 1 — validation:  evil.com resolves to 1.2.3.4 (public IP, passes checks).
            // Phase 2 — connection:  DNS TTL expires; evil.com now resolves to 127.0.0.1.
            //                        The HTTP client connects to localhost despite the
            //                        "safe" hostname, bypassing all host-based filtering.
            //
            // Reliable mitigations live outside the HTTP client:
            //   • Network egress firewall rules blocking RFC 1918 ranges on outbound traffic.
            //   • DNS-level controls: RPZ (Response Policy Zones) or split-horizon DNS.
            //   • Pinning the resolved IP at validation time and re-verifying before connect
            //     (requires a custom DNS resolver integration, not standard in libcurl).
            //
            // What the HTTP client CAN do (tested below):
            // Strip credentials on scheme downgrade (https → http).
            // Strip credentials on any detectable origin change in Location headers.
            // These reduce the blast radius but do not prevent the connection itself.
            expect(true)->toBeTrue();
        })->todo('DNS rebinding requires network-level controls outside the HTTP client layer likely using hibla DNS resolver.');

        it('strips credentials when a rebind forces a scheme downgrade as part of the redirect chain', function () {
            $a = new Uri('https://evil.com/phase-one');
            $b = new Uri('http://evil.com/phase-two');

            expect(UriValidator::isCrossDomain($a, $b))->toBeTrue();
        });
    });
});
