<?php

declare(strict_types=1);

use Hibla\HttpClient\Validators\HeaderValidator;

describe('HeaderValidator::isValidName()', function (): void {

    describe('valid names', function (): void {

        it('accepts standard well-known header names', function (string $name): void {
            expect(HeaderValidator::isValidName($name))->toBeTrue();
        })->with([
            'Content-Type',
            'Authorization',
            'Accept',
            'X-Request-ID',
            'Cache-Control',
            'Transfer-Encoding',
            'WWW-Authenticate',
            'X-Custom-Header',
            'Host',
            'Accept-Encoding',
        ]);

        it('accepts all allowed tchar symbols', function (string $name): void {
            expect(HeaderValidator::isValidName($name))->toBeTrue();
        })->with([
            '!' => 'exclamation-mark',
            '#' => 'hash',
            '$' => 'dollar',
            '%' => 'percent',
            '&' => 'ampersand',
            "'" => 'single-quote',
            '*' => 'asterisk',
            '+' => 'plus',
            '-' => 'hyphen',
            '.' => 'period',
            '^' => 'caret',
            '_' => 'underscore',
            '`' => 'backtick',
            '|' => 'pipe',
            '~' => 'tilde',
        ]);

        it('accepts single-character alphabetic names', function (string $name): void {
            expect(HeaderValidator::isValidName($name))->toBeTrue();
        })->with(['A', 'Z', 'a', 'z']);

        it('accepts single-character digit names', function (string $name): void {
            expect(HeaderValidator::isValidName($name))->toBeTrue();
        })->with(['0', '9']);

        it('accepts names that are purely numeric', function (): void {
            expect(HeaderValidator::isValidName('12345'))->toBeTrue();
        });

        it('accepts names mixing all allowed character classes', function (): void {
            expect(HeaderValidator::isValidName('X-My_Header.v2~!'))->toBeTrue();
        });

        it('accepts both upper and lower case ASCII letters', function (): void {
            expect(HeaderValidator::isValidName('abcdefghijklmnopqrstuvwxyz'))->toBeTrue();
            expect(HeaderValidator::isValidName('ABCDEFGHIJKLMNOPQRSTUVWXYZ'))->toBeTrue();
        });

    });

    describe('invalid names', function (): void {

        it('rejects an empty string', function (): void {
            expect(HeaderValidator::isValidName(''))->toBeFalse();
        });

        it('rejects names containing spaces', function (): void {
            expect(HeaderValidator::isValidName('Content Type'))->toBeFalse();
            expect(HeaderValidator::isValidName(' Content-Type'))->toBeFalse();
            expect(HeaderValidator::isValidName('Content-Type '))->toBeFalse();
        });

        it('rejects names containing a colon', function (): void {
            expect(HeaderValidator::isValidName('X-Foo:Bar'))->toBeFalse();
            expect(HeaderValidator::isValidName(':pseudo-header'))->toBeFalse();
        });

        it('rejects names containing CR', function (): void {
            expect(HeaderValidator::isValidName("X-Foo\r"))->toBeFalse();
            expect(HeaderValidator::isValidName("X-Foo\rBar"))->toBeFalse();
        });

        it('rejects names containing LF', function (): void {
            expect(HeaderValidator::isValidName("X-Foo\n"))->toBeFalse();
            expect(HeaderValidator::isValidName("X-Foo\nX-Bar"))->toBeFalse();
        });

        it('rejects names containing CRLF (response-splitting attempt)', function (): void {
            expect(HeaderValidator::isValidName("X-Foo\r\nX-Bar"))->toBeFalse();
            expect(HeaderValidator::isValidName("X-Foo\r\n"))->toBeFalse();
        });

        it('rejects names containing NUL', function (): void {
            expect(HeaderValidator::isValidName("X-Foo\x00"))->toBeFalse();
        });

        it('rejects names containing other control characters', function (string $name): void {
            expect(HeaderValidator::isValidName($name))->toBeFalse();
        })->with([
            "X-Foo\x01",
            "X-Foo\x08",
            "X-Foo\x0B",
            "X-Foo\x0C",
            "X-Foo\x0E",
            "X-Foo\x1F",
            "X-Foo\x7F", // DEL
        ]);

        it('rejects names containing RFC 9110 delimiter characters', function (string $name): void {
            // Delimiters: ( ) < > @ , ; : \ " / [ ] ? = { }
            expect(HeaderValidator::isValidName($name))->toBeFalse();
        })->with([
            'X(Foo)',
            'X<Foo>',
            'X@Foo',
            'X,Foo',
            'X;Foo',
            'X:Foo',
            'X\\Foo',
            'X"Foo"',
            'X/Foo',
            'X[Foo]',
            'X?Foo',
            'X=Foo',
            'X{Foo}',
        ]);

        it('rejects names containing high-byte (non-ASCII) characters', function (): void {
            expect(HeaderValidator::isValidName("X-Foo\x80"))->toBeFalse();
            expect(HeaderValidator::isValidName("X-Foo\xFF"))->toBeFalse();
            expect(HeaderValidator::isValidName('X-Héader'))->toBeFalse();
        });

    });

});

describe('HeaderValidator::assertValidName()', function (): void {

    it('does not throw for a valid header name', function (): void {
        expect(fn () => HeaderValidator::assertValidName('Content-Type'))->not->toThrow(InvalidArgumentException::class);
    });

    it('throws InvalidArgumentException for an empty name', function (): void {
        expect(fn () => HeaderValidator::assertValidName(''))->toThrow(
            InvalidArgumentException::class,
            'empty',
        );
    });

    it('throws InvalidArgumentException for a name containing CR LF', function (): void {
        expect(fn () => HeaderValidator::assertValidName("Injected\r\nHeader"))->toThrow(
            InvalidArgumentException::class,
        );
    });

    it('throws InvalidArgumentException for a name containing a space', function (): void {
        expect(fn () => HeaderValidator::assertValidName('Bad Header'))->toThrow(
            InvalidArgumentException::class,
        );
    });

    it('includes the offending name in the exception message', function (): void {
        expect(fn () => HeaderValidator::assertValidName('Bad Header'))
            ->toThrow(InvalidArgumentException::class, 'Bad Header')
        ;
    });

});

describe('HeaderValidator::isValidValue()', function (): void {

    describe('valid values', function (): void {

        it('accepts an empty string', function (): void {
            // RFC 9110 §5.5 permits zero-length field values.
            expect(HeaderValidator::isValidValue(''))->toBeTrue();
        });

        it('accepts common well-formed header values', function (string $value): void {
            expect(HeaderValidator::isValidValue($value))->toBeTrue();
        })->with([
            'application/json',
            'Bearer eyJhbGciOiJIUzI1NiJ9',
            'text/html; charset=UTF-8',
            'gzip, deflate, br',
            'no-cache, no-store, must-revalidate',
            'max-age=3600, public',
            'en-US,en;q=0.9',
            '12345',
            'Mon, 07 Apr 2025 12:00:00 GMT',
            '*/*',
        ]);

        it('accepts HTAB (0x09) as internal whitespace between tokens', function (): void {
            expect(HeaderValidator::isValidValue("value1\tvalue2"))->toBeTrue();
        });

        it('accepts visible ASCII characters (0x21–0x7E)', function (): void {
            // Build a string of all VCHAR bytes
            $allVchar = implode('', array_map('chr', range(0x21, 0x7E)));
            expect(HeaderValidator::isValidValue($allVchar))->toBeTrue();
        });

        it('accepts obs-text bytes (0x80–0xFF) for legacy interoperability', function (): void {
            // RFC 9110 ABNF still allows obs-text; stripping it would break
            // interoperability with legacy servers.
            expect(HeaderValidator::isValidValue("\x80value\xFF"))->toBeTrue();
            expect(HeaderValidator::isValidValue("value\xC3\xA9"))->toBeTrue(); // UTF-8 "é"
        });

        it('accepts a value consisting of a single printable character', function (): void {
            expect(HeaderValidator::isValidValue('A'))->toBeTrue();
            expect(HeaderValidator::isValidValue('*'))->toBeTrue();
            expect(HeaderValidator::isValidValue('1'))->toBeTrue();
        });

        it('accepts SP (0x20) as internal whitespace between tokens', function (): void {
            expect(HeaderValidator::isValidValue('token1 token2'))->toBeTrue();
            expect(HeaderValidator::isValidValue('a b c'))->toBeTrue();
        });

    });

    describe('CR / LF / NUL injection — RFC 9110 §5.5', function (): void {

        it('rejects a value containing a bare CR (0x0D)', function (): void {
            expect(HeaderValidator::isValidValue("value\r"))->toBeFalse();
            expect(HeaderValidator::isValidValue("val\rue"))->toBeFalse();
        });

        it('rejects a value containing a bare LF (0x0A)', function (): void {
            expect(HeaderValidator::isValidValue("value\n"))->toBeFalse();
            expect(HeaderValidator::isValidValue("val\nue"))->toBeFalse();
        });

        it('rejects a value containing CRLF (HTTP response-splitting attempt)', function (): void {
            expect(HeaderValidator::isValidValue("value\r\n"))->toBeFalse();
            expect(HeaderValidator::isValidValue("val\r\nX-Injected: evil"))->toBeFalse();
        });

        it('rejects a value containing double CRLF (HTTP response-body injection)', function (): void {
            // Two CRLFs mark the end of the header section and the start of
            // the body — injecting this would let an attacker forge responses.
            expect(HeaderValidator::isValidValue("value\r\n\r\n<script>alert(1)</script>"))->toBeFalse();
        });

        it('rejects a value containing NUL (0x00)', function (): void {
            expect(HeaderValidator::isValidValue("val\x00ue"))->toBeFalse();
            expect(HeaderValidator::isValidValue("\x00"))->toBeFalse();
        });

        it('rejects URL-encoded CRLF variants in the raw byte form', function (): void {
            // These are raw CR/LF bytes, not the percent-encoded string "%0D%0A".
            // Percent-encoded strings are decoded upstream before reaching headers.
            expect(HeaderValidator::isValidValue("\r\n"))->toBeFalse();
        });

    });

    describe('control character rejection — RFC 9110 §5.5', function (): void {

        it('rejects non-HTAB control characters (0x01–0x08)', function (string $value): void {
            expect(HeaderValidator::isValidValue($value))->toBeFalse();
        })->with(array_map(
            // FIX: use chr() to embed the actual control byte, not string
            // concatenation which produces the literal text "val\x01ue" etc.
            fn (int $byte): string => 'val' . chr($byte) . 'ue',
            range(0x01, 0x08),
        ));

        it('rejects VT (0x0B) and FF (0x0C)', function (): void {
            expect(HeaderValidator::isValidValue("val\x0Bue"))->toBeFalse();
            expect(HeaderValidator::isValidValue("val\x0Cue"))->toBeFalse();
        });

        it('rejects control characters 0x0E–0x1F', function (string $value): void {
            expect(HeaderValidator::isValidValue($value))->toBeFalse();
        })->with(array_map(
            fn (int $byte): string => 'val' . chr($byte) . 'ue',
            range(0x0E, 0x1F),
        ));

        it('rejects DEL (0x7F)', function (): void {
            expect(HeaderValidator::isValidValue("val\x7Fue"))->toBeFalse();
        });

        it('accepts HTAB (0x09) as the sole permitted control-range whitespace', function (): void {
            expect(HeaderValidator::isValidValue("val\x09ue"))->toBeTrue();
        });

    });

    describe('leading and trailing whitespace — RFC 9110 §5.5', function (): void {

        it('rejects a value with a leading space', function (): void {
            expect(HeaderValidator::isValidValue(' application/json'))->toBeFalse();
        });

        it('rejects a value with a trailing space', function (): void {
            expect(HeaderValidator::isValidValue('application/json '))->toBeFalse();
        });

        it('rejects a value with a leading HTAB', function (): void {
            expect(HeaderValidator::isValidValue("\tapplication/json"))->toBeFalse();
        });

        it('rejects a value with a trailing HTAB', function (): void {
            expect(HeaderValidator::isValidValue("application/json\t"))->toBeFalse();
        });

        it('rejects a value that is only whitespace', function (): void {
            expect(HeaderValidator::isValidValue(' '))->toBeFalse();
            expect(HeaderValidator::isValidValue("\t"))->toBeFalse();
            expect(HeaderValidator::isValidValue("  \t  "))->toBeFalse();
        });

        it('accepts internal spaces between tokens', function (): void {
            // Spaces inside the value are valid — only leading/trailing are forbidden.
            expect(HeaderValidator::isValidValue('token1 token2'))->toBeTrue();
            expect(HeaderValidator::isValidValue('max-age=0, no-cache'))->toBeTrue();
        });

    });

    describe('obs-fold rejection — RFC 9110 §5.5', function (): void {

        it('rejects CRLF followed by SP (classic obs-fold)', function (): void {
            // obs-fold = CRLF 1*(SP / HTAB) — explicitly deprecated
            expect(HeaderValidator::isValidValue("multi\r\n line"))->toBeFalse();
        });

        it('rejects CRLF followed by HTAB (obs-fold with tab)', function (): void {
            expect(HeaderValidator::isValidValue("multi\r\n\tline"))->toBeFalse();
        });

    });

});

describe('HeaderValidator::assertValidValue()', function (): void {

    it('does not throw for a valid header value', function (): void {
        expect(fn () => HeaderValidator::assertValidValue('application/json'))
            ->not->toThrow(InvalidArgumentException::class)
        ;
    });

    it('does not throw for an empty string', function (): void {
        expect(fn () => HeaderValidator::assertValidValue(''))
            ->not->toThrow(InvalidArgumentException::class)
        ;
    });

    it('throws InvalidArgumentException for a value containing CR', function (): void {
        expect(fn () => HeaderValidator::assertValidValue("value\r"))
            ->toThrow(InvalidArgumentException::class)
        ;
    });

    it('throws InvalidArgumentException for a value containing LF', function (): void {
        expect(fn () => HeaderValidator::assertValidValue("value\n"))
            ->toThrow(InvalidArgumentException::class)
        ;
    });

    it('throws InvalidArgumentException for a value containing NUL', function (): void {
        expect(fn () => HeaderValidator::assertValidValue("val\x00ue"))
            ->toThrow(InvalidArgumentException::class)
        ;
    });

    it('throws InvalidArgumentException for a value with leading whitespace', function (): void {
        expect(fn () => HeaderValidator::assertValidValue(' value'))
            ->toThrow(InvalidArgumentException::class)
        ;
    });

    it('throws InvalidArgumentException for a value with trailing whitespace', function (): void {
        expect(fn () => HeaderValidator::assertValidValue('value '))
            ->toThrow(InvalidArgumentException::class)
        ;
    });

    it('throws InvalidArgumentException for a CRLF injection payload', function (): void {
        expect(fn () => HeaderValidator::assertValidValue("value\r\nX-Injected: evil"))
            ->toThrow(InvalidArgumentException::class)
        ;
    });

    it('exception message identifies CR LF and NUL as the cause', function (): void {
        expect(fn () => HeaderValidator::assertValidValue("value\r\n"))
            ->toThrow(InvalidArgumentException::class, '0x0D')
        ;
    });

    it('exception message identifies leading whitespace as the cause', function (): void {
        expect(fn () => HeaderValidator::assertValidValue(' value'))
            ->toThrow(InvalidArgumentException::class, 'whitespace')
        ;
    });

});

describe('HeaderValidator::isValidMethod()', function (): void {

    describe('valid methods', function (): void {

        it('accepts standard HTTP methods', function (string $method): void {
            expect(HeaderValidator::isValidMethod($method))->toBeTrue();
        })->with([
            'GET',
            'POST',
            'PUT',
            'DELETE',
            'PATCH',
            'HEAD',
            'OPTIONS',
            'TRACE',
            'CONNECT',
        ]);

        it('accepts WebDAV and other extension methods', function (string $method): void {
            expect(HeaderValidator::isValidMethod($method))->toBeTrue();
        })->with([
            'PROPFIND',
            'PROPPATCH',
            'MKCOL',
            'COPY',
            'MOVE',
            'LOCK',
            'UNLOCK',
            'SEARCH',
        ]);

        it('accepts lowercase methods (upper-casing is the caller\'s responsibility)', function (): void {
            // HeaderValidator validates the token grammar; it does not enforce case.
            // Request::withMethod() calls strtoupper() before assertValidMethod().
            expect(HeaderValidator::isValidMethod('get'))->toBeTrue();
            expect(HeaderValidator::isValidMethod('post'))->toBeTrue();
        });

        it('accepts custom single-character method tokens', function (): void {
            expect(HeaderValidator::isValidMethod('X'))->toBeTrue();
        });

    });

    describe('invalid methods', function (): void {

        it('rejects an empty string', function (): void {
            expect(HeaderValidator::isValidMethod(''))->toBeFalse();
        });

        it('rejects methods containing a space', function (): void {
            expect(HeaderValidator::isValidMethod('GET POST'))->toBeFalse();
            expect(HeaderValidator::isValidMethod('GET '))->toBeFalse();
            expect(HeaderValidator::isValidMethod(' GET'))->toBeFalse();
        });

        it('rejects methods containing CR', function (): void {
            expect(HeaderValidator::isValidMethod("GET\r"))->toBeFalse();
        });

        it('rejects methods containing LF', function (): void {
            expect(HeaderValidator::isValidMethod("GET\n"))->toBeFalse();
        });

        it('rejects methods containing CRLF (request-line injection attempt)', function (): void {
            expect(HeaderValidator::isValidMethod("GET\r\n"))->toBeFalse();
            expect(HeaderValidator::isValidMethod("GET\r\nPOST / HTTP/1.1"))->toBeFalse();
        });

        it('rejects methods containing NUL', function (): void {
            expect(HeaderValidator::isValidMethod("GE\x00T"))->toBeFalse();
        });

        it('rejects methods containing delimiter characters', function (string $method): void {
            expect(HeaderValidator::isValidMethod($method))->toBeFalse();
        })->with([
            'GET/path',
            'GET:value',
            'GET(args)',
            'GET[1]',
            'GET{x}',
            'GET@host',
            'GET,POST',
            'GET;POST',
        ]);

    });

});

describe('HeaderValidator::assertValidMethod()', function (): void {

    it('does not throw for a valid HTTP method', function (): void {
        expect(fn () => HeaderValidator::assertValidMethod('GET'))
            ->not->toThrow(InvalidArgumentException::class)
        ;
    });

    it('throws InvalidArgumentException for an empty method', function (): void {
        expect(fn () => HeaderValidator::assertValidMethod(''))
            ->toThrow(InvalidArgumentException::class, 'empty')
        ;
    });

    it('throws InvalidArgumentException for a method containing a space', function (): void {
        expect(fn () => HeaderValidator::assertValidMethod('GET POST'))
            ->toThrow(InvalidArgumentException::class)
        ;
    });

    it('throws InvalidArgumentException for a CRLF injection in the method', function (): void {
        expect(fn () => HeaderValidator::assertValidMethod("GET\r\nX-Header: injected"))
            ->toThrow(InvalidArgumentException::class)
        ;
    });

    it('includes the offending method string in the exception message', function (): void {
        expect(fn () => HeaderValidator::assertValidMethod('BAD METHOD'))
            ->toThrow(InvalidArgumentException::class, 'BAD METHOD')
        ;
    });

});

describe('HeaderValidator instantiation', function (): void {

    it('cannot be instantiated — constructor is private', function (): void {
        $reflection = new ReflectionClass(HeaderValidator::class);
        expect($reflection->getConstructor()?->isPrivate())->toBeTrue();
    });

    it('is declared final — cannot be extended', function (): void {
        $reflection = new ReflectionClass(HeaderValidator::class);
        expect($reflection->isFinal())->toBeTrue();
    });

});

describe('HeaderValidator known attack payloads', function (): void {

    // CVE-2020-26116 — Python http.client CRLF injection via method parameter
    it('rejects the CVE-2020-26116 method injection payload', function (): void {
        expect(HeaderValidator::isValidMethod("GET\r\nHost: evil.com\r\n\r\n"))->toBeFalse();
        expect(HeaderValidator::isValidMethod("CONNECT\r\nAuthorization: Bearer fake"))->toBeFalse();
    });

    it('rejects header name injection that fakes a new header field', function (): void {
        expect(HeaderValidator::isValidName("X-Foo\r\nX-Bar"))->toBeFalse();
        expect(HeaderValidator::isValidName("X-Legit: ignored\r\nX-Evil"))->toBeFalse();
    });

    it('rejects header value injection that fakes a new header field', function (): void {
        expect(HeaderValidator::isValidValue("token\r\nX-Evil: injected"))->toBeFalse();
    });

    it('rejects classic web cache-poisoning payload in value', function (): void {
        // Attacker tries to split the response and inject a cached poisoned response.
        $payload = "innocent\r\nContent-Length: 0\r\n\r\nHTTP/1.1 200 OK\r\nContent-Type: text/html\r\n\r\n<script>alert(1)</script>";
        expect(HeaderValidator::isValidValue($payload))->toBeFalse();
    });

    it('rejects session-fixation via Set-Cookie injection in value', function (): void {
        $payload = "valid-value\r\nSet-Cookie: session=attacker; Path=/";
        expect(HeaderValidator::isValidValue($payload))->toBeFalse();
    });

    it('rejects open-redirect injection via Location header value', function (): void {
        $payload = "https://good.example.com\r\nLocation: https://evil.example.com";
        expect(HeaderValidator::isValidValue($payload))->toBeFalse();
    });

    it('rejects null-byte header name smuggling attempts', function (): void {
        expect(HeaderValidator::isValidName("X-Foo\x00Bar"))->toBeFalse();
        expect(HeaderValidator::isValidValue("value\x00injected"))->toBeFalse();
    });

    it('rejects URL-decoded CRLF that bypasses naive string-search filters', function (): void {
        expect(HeaderValidator::isValidValue("\r\n"))->toBeFalse();   // decoded %0D%0A
        expect(HeaderValidator::isValidValue("\n"))->toBeFalse();     // decoded %0A
        expect(HeaderValidator::isValidValue("\r"))->toBeFalse();     // decoded %0D
    });

});
