<?php

declare(strict_types=1);

use Hibla\HttpClient\CookieJar;
use Hibla\HttpClient\Interfaces\Cookie\CookieJarInterface;
use Hibla\HttpClient\Request;
use Hibla\HttpClient\Stream;
use Hibla\HttpClient\Uri;

afterEach(function () {
    Mockery::close();
});

describe('Request Construction & Basic Getters', function () {
    it('constructs with defaults when no arguments are supplied', function () {
        $request = new Request();

        expect($request->getMethod())->toBe('GET');
        expect((string) $request->getUri())->toBe('/');
        expect($request->getProtocolVersion())->toBe('2.0');
        expect($request->hasExplicitBody())->toBeFalse();
    });

    it('constructs with a method and URI string', function () {
        $request = new Request('POST', 'https://api.example.com/v1/orders');

        expect($request->getMethod())->toBe('POST');
        expect((string) $request->getUri())->toBe('https://api.example.com/v1/orders');
    });

    it('constructs with a UriInterface instance', function () {
        $uri = new Uri('https://api.example.com/v1/users');
        $request = new Request('GET', $uri);

        expect($request->getUri())->toBe($uri);
    });

    it('constructs with headers', function () {
        $request = new Request('GET', '', ['Accept' => 'application/json', 'X-Foo' => 'bar']);

        expect($request->getHeaderLine('Accept'))->toBe('application/json');
        expect($request->getHeaderLine('X-Foo'))->toBe('bar');
    });

    it('constructs with a string body', function () {
        $request = new Request('POST', '', [], 'raw body content');

        expect($request->hasExplicitBody())->toBeTrue();
        expect((string) $request->getBody())->toBe('raw body content');
    });

    it('constructs with a StreamInterface body', function () {
        $stream = Stream::fromString('streamed content');
        $request = new Request('POST', '', [], $stream);

        expect($request->getBody())->toBe($stream);
        expect($request->hasExplicitBody())->toBeTrue();
    });

    it('constructs with a custom protocol version', function () {
        $request = new Request('GET', '', [], null, '2');

        expect($request->getProtocolVersion())->toBe('2');
    });

    it('normalises a lower-case method to upper-case during construction', function () {
        $request = new Request('post', 'https://api.example.com');

        expect($request->getMethod())->toBe('POST');
    });

    it('syncs the Host header from the URI during construction', function () {
        $request = new Request('GET', 'https://api.example.com/path');

        expect($request->getHeaderLine('Host'))->toBe('api.example.com');
    });

    it('syncs Host with port when the URI contains a non-standard port', function () {
        $request = new Request('GET', 'https://api.example.com:8443/path');

        expect($request->getHeaderLine('Host'))->toBe('api.example.com:8443');
    });

    it('throws for an invalid method token during construction', function () {
        expect(fn () => new Request('INVALID METHOD'))->toThrow(InvalidArgumentException::class);
    });
});

describe('Immutability', function () {
    it('withMethod() returns a new instance and does not mutate the original', function () {
        $r1 = new Request('GET');
        $r2 = $r1->withMethod('POST');

        expect($r1)->not->toBe($r2);
        expect($r1->getMethod())->toBe('GET');
        expect($r2->getMethod())->toBe('POST');
    });

    it('withUri() returns a new instance and does not mutate the original', function () {
        $r1 = new Request('GET', 'https://example.com');
        $r2 = $r1->withUri(new Uri('https://other.com'));

        expect($r1)->not->toBe($r2);
        expect((string) $r1->getUri())->toBe('https://example.com/');
        expect((string) $r2->getUri())->toBe('https://other.com/');
    });

    it('withHeader() returns a new instance and does not mutate the original', function () {
        $r1 = new Request();
        $r2 = $r1->withHeader('X-Foo', 'bar');

        expect($r1)->not->toBe($r2);
        expect($r1->hasHeader('X-Foo'))->toBeFalse();
        expect($r2->getHeaderLine('X-Foo'))->toBe('bar');
    });

    it('withBody() returns a new instance and does not mutate the original', function () {
        $r1 = new Request();
        $r2 = $r1->withBody(Stream::fromString('hello'));

        expect($r1)->not->toBe($r2);
        expect((string) $r1->getBody())->toBe('');
        expect((string) $r2->getBody())->toBe('hello');
    });
});

describe('Method', function () {
    it('withMethod() stores the method in upper-case', function () {
        $request = (new Request())->withMethod('delete');

        expect($request->getMethod())->toBe('DELETE');
    });

    it('withMethod() returns the same instance when the method is unchanged', function () {
        $request = new Request('GET');

        expect($request->withMethod('GET'))->toBe($request);
    });

    it('withMethod() throws for an empty string', function () {
        expect(fn () => (new Request())->withMethod(''))->toThrow(InvalidArgumentException::class);
    });

    it('withMethod() throws for a method containing spaces', function () {
        expect(fn () => (new Request())->withMethod('GET POST'))->toThrow(InvalidArgumentException::class);
    });

    it('withMethod() throws for a method containing CR/LF', function () {
        expect(fn () => (new Request())->withMethod("GET\r\n"))->toThrow(InvalidArgumentException::class);
    });
});

describe('URI & Request Target', function () {
    it('getRequestTarget() derives the target from the URI path', function () {
        $request = new Request('GET', 'https://example.com/api/resource');

        expect($request->getRequestTarget())->toBe('/api/resource');
    });

    it('getRequestTarget() includes the query string', function () {
        $request = new Request('GET', 'https://example.com/search?q=test&page=2');

        expect($request->getRequestTarget())->toBe('/search?q=test&page=2');
    });

    it('getRequestTarget() defaults to "/" when the URI path is empty', function () {
        $request = new Request('GET', 'https://example.com');

        expect($request->getRequestTarget())->toBe('/');
    });

    it('withRequestTarget() overrides the derived target', function () {
        $request = (new Request('GET', 'https://example.com/original'))
            ->withRequestTarget('/override?foo=bar')
        ;

        expect($request->getRequestTarget())->toBe('/override?foo=bar');
    });

    it('withRequestTarget() returns the same instance when the value is unchanged', function () {
        $request = (new Request())->withRequestTarget('/same');

        expect($request->withRequestTarget('/same'))->toBe($request);
    });

    it('withUri() updates the Host header by default', function () {
        $request = (new Request('GET', 'https://old.example.com'))
            ->withUri(new Uri('https://new.example.com'))
        ;

        expect($request->getHeaderLine('Host'))->toBe('new.example.com');
    });

    it('withUri() preserves the Host header when $preserveHost is true and Host is set', function () {
        $request = (new Request('GET', 'https://old.example.com'))
            ->withUri(new Uri('https://new.example.com'), preserveHost: true)
        ;

        expect($request->getHeaderLine('Host'))->toBe('old.example.com');
    });

    it('withUri() returns the same instance when the URI is identical', function () {
        $uri = new Uri('https://example.com');
        $request = new Request('GET', $uri);

        expect($request->withUri($uri))->toBe($request);
    });
});

describe('Header Helpers', function () {
    it('contentType() sets the Content-Type header', function () {
        $request = (new Request())->contentType('text/plain');

        expect($request->getHeaderLine('Content-Type'))->toBe('text/plain');
    });

    it('accept() sets the Accept header', function () {
        $request = (new Request())->accept('application/json');

        expect($request->getHeaderLine('Accept'))->toBe('application/json');
    });

    it('asJson() sets Content-Type to application/json', function () {
        expect((new Request())->asJson()->getHeaderLine('Content-Type'))
            ->toBe('application/json')
        ;
    });

    it('asForm() sets Content-Type to application/x-www-form-urlencoded', function () {
        expect((new Request())->asForm()->getHeaderLine('Content-Type'))
            ->toBe('application/x-www-form-urlencoded')
        ;
    });

    it('asXml() sets Content-Type to application/xml', function () {
        expect((new Request())->asXml()->getHeaderLine('Content-Type'))
            ->toBe('application/xml')
        ;
    });

    it('withHeaders() sets multiple headers in one call', function () {
        $request = (new Request())->withHeaders([
            'Accept' => 'application/json',
            'X-Request-ID' => 'abc-123',
        ]);

        expect($request->getHeaderLine('Accept'))->toBe('application/json');
        expect($request->getHeaderLine('X-Request-ID'))->toBe('abc-123');
    });

    it('withAddedHeader() appends a value to an existing header', function () {
        $request = (new Request())
            ->withHeader('X-Multi', 'first')
            ->withAddedHeader('X-Multi', 'second')
        ;

        expect($request->getHeader('X-Multi'))->toBe(['first', 'second']);
    });

    it('withoutHeader() removes an existing header', function () {
        $request = (new Request())
            ->withHeader('X-Foo', 'bar')
            ->withoutHeader('X-Foo')
        ;

        expect($request->hasHeader('X-Foo'))->toBeFalse();
    });
});

describe('Authentication', function () {
    it('withToken() sets a Bearer Authorization header', function () {
        $request = (new Request())->withToken('my-secret-token');

        expect($request->getHeaderLine('Authorization'))->toBe('Bearer my-secret-token');
    });

    it('withToken() supports a custom scheme', function () {
        $request = (new Request())->withToken('my-key', 'ApiKey');

        expect($request->getHeaderLine('Authorization'))->toBe('ApiKey my-key');
    });

    it('withToken() strips a duplicate scheme prefix from the token', function () {
        $request = (new Request())->withToken('Bearer my-secret-token');

        expect($request->getHeaderLine('Authorization'))->toBe('Bearer my-secret-token');
    });

    it('withToken() clears any existing auth tuple', function () {
        $request = (new Request())
            ->withBasicAuth('user', 'pass')
            ->withToken('new-token')
        ;

        expect($request->getAuth())->toBeNull();
        expect($request->getHeaderLine('Authorization'))->toBe('Bearer new-token');
    });

    it('withToken() throws for an invalid scheme token', function () {
        expect(fn () => (new Request())->withToken('tok', 'Bad Scheme'))
            ->toThrow(InvalidArgumentException::class)
        ;
    });

    it('withBasicAuth() stores the auth tuple and removes the Authorization header', function () {
        $request = (new Request())
            ->withHeader('Authorization', 'Bearer old')
            ->withBasicAuth('alice', 's3cr3t')
        ;

        expect($request->getAuth())->toBe(['basic', 'alice', 's3cr3t']);
        expect($request->hasHeader('Authorization'))->toBeFalse();
    });

    it('withDigestAuth() stores the auth tuple and removes the Authorization header', function () {
        $request = (new Request())
            ->withHeader('Authorization', 'Bearer old')
            ->withDigestAuth('bob', 'p@ss')
        ;

        expect($request->getAuth())->toBe(['digest', 'bob', 'p@ss']);
        expect($request->hasHeader('Authorization'))->toBeFalse();
    });
});

describe('Body Helpers', function () {
    it('body() writes a raw string and marks the body as explicitly set', function () {
        $request = (new Request())->body('raw content');

        expect((string) $request->getBody())->toBe('raw content');
        expect($request->hasExplicitBody())->toBeTrue();
    });

    it('withJson() encodes an array and sets Content-Type to application/json', function () {
        $request = (new Request())->withJson(['key' => 'value']);

        expect($request->getHeaderLine('Content-Type'))->toBe('application/json');
        expect(json_decode((string) $request->getBody(), true))->toBe(['key' => 'value']);
        expect($request->hasExplicitBody())->toBeTrue();
    });

    it('withJson() throws for data that cannot be encoded', function () {
        expect(fn () => (new Request())->withJson(["\xB1\x31"]))
            ->toThrow(InvalidArgumentException::class)
        ;
    });

    it('withXml() accepts a string and sets Content-Type to application/xml', function () {
        $xml = '<root><item>1</item></root>';
        $request = (new Request())->withXml($xml);

        expect($request->getHeaderLine('Content-Type'))->toBe('application/xml');
        expect((string) $request->getBody())->toBe($xml);
    });

    it('withXml() accepts a SimpleXMLElement', function () {
        $xml = new SimpleXMLElement('<root><item>1</item></root>');
        $request = (new Request())->withXml($xml);

        expect($request->getHeaderLine('Content-Type'))->toBe('application/xml');
        expect((string) $request->getBody())->toContain('<item>1</item>');
    });

    it('withForm() URL-encodes an array and sets the correct Content-Type', function () {
        $request = (new Request())->withForm(['foo' => 'bar', 'baz' => 'qux']);

        expect($request->getHeaderLine('Content-Type'))
            ->toBe('application/x-www-form-urlencoded')
        ;
        expect((string) $request->getBody())->toBe('foo=bar&baz=qux');
    });

    it('withMultipart() stores the field map in options and removes Content-Type', function () {
        $request = (new Request())
            ->withHeader('Content-Type', 'application/json')
            ->withMultipart(['field' => 'value'])
        ;

        expect($request->getOptions()['multipart'])->toBe(['field' => 'value']);
        expect($request->hasHeader('Content-Type'))->toBeFalse();
        expect($request->hasExplicitBody())->toBeTrue();
    });

    it('withMultipart() merges into an existing multipart map', function () {
        $request = (new Request())
            ->withMultipart(['a' => '1'])
            ->withMultipart(['b' => '2'])
        ;

        expect($request->getOptions()['multipart'])->toBe(['a' => '1', 'b' => '2']);
    });

    it('withMultipartEntry() adds a single resolved entry to the multipart map', function () {
        $entry = ['contents' => 'data', 'filename' => 'file.txt'];
        $request = (new Request())->withMultipartEntry('upload', $entry);

        expect($request->getOptions()['multipart']['upload'])->toBe($entry);
        expect($request->hasHeader('Content-Type'))->toBeFalse();
    });
});

describe('User-Agent', function () {
    it('withUserAgent() stores the value and returns a new instance', function () {
        $r1 = new Request();
        $r2 = $r1->withUserAgent('MyClient/1.0');

        expect($r1)->not->toBe($r2);
        expect($r1->getUserAgent())->toBeNull();
        expect($r2->getUserAgent())->toBe('MyClient/1.0');
    });
});

describe('Cookie Helpers', function () {
    it('withCookie() appends a cookie to the Cookie header', function () {
        $request = (new Request())->withCookie('session', 'abc123');

        expect($request->getHeaderLine('Cookie'))->toBe('session=abc123');
    });

    it('withCookie() appends to an existing Cookie header', function () {
        $request = (new Request())
            ->withCookie('a', '1')
            ->withCookie('b', '2')
        ;

        expect($request->getHeaderLine('Cookie'))->toBe('a=1; b=2');
    });

    it('withCookie() throws for an invalid cookie name', function () {
        expect(fn () => (new Request())->withCookie('invalid name', 'value'))
            ->toThrow(InvalidArgumentException::class)
        ;
    });

    it('withCookie() throws for an invalid cookie value', function () {
        expect(fn () => (new Request())->withCookie('name', 'val;ue'))
            ->toThrow(InvalidArgumentException::class)
        ;
    });

    it('withCookies() sets multiple cookies at once', function () {
        $request = (new Request())->withCookies(['x' => '1', 'y' => '2']);

        expect($request->getHeaderLine('Cookie'))->toBe('x=1; y=2');
    });

    it('withCookieJar() attaches a fresh CookieJar', function () {
        $request = (new Request())->withCookieJar();

        expect($request->getCookieJar())->toBeInstanceOf(CookieJar::class);
    });

    it('useCookieJar() attaches the provided jar', function () {
        $jar = Mockery::mock(CookieJarInterface::class);
        $request = (new Request())->useCookieJar($jar);

        expect($request->getCookieJar())->toBe($jar);
    });

    it('clearCookies() removes the Cookie header and clears the jar', function () {
        $jar = Mockery::mock(CookieJarInterface::class);
        $jar->shouldReceive('clear')->once();

        $request = (new Request())
            ->withCookie('a', '1')
            ->useCookieJar($jar)
            ->clearCookies()
        ;

        expect($request->hasHeader('Cookie'))->toBeFalse();
    });

    it('cookieWithAttributes() creates a jar when none is set and stores the cookie', function () {
        $request = (new Request())->cookieWithAttributes('pref', 'dark', [
            'path' => '/',
            'secure' => true,
            'httpOnly' => true,
        ]);

        $jar = $request->getCookieJar();
        expect($jar)->not->toBeNull();
    });

    it('cookieWithAttributes() reuses an existing jar', function () {
        $r1 = (new Request())->withCookieJar();
        $r2 = $r1->cookieWithAttributes('token', 'xyz', []);

        expect($r2->getCookieJar())->toBe($r1->getCookieJar());
    });
});
