<?php

use Hibla\HttpClient\Uri;



test('parses all components of a complex URI correctly', function () {
    $uriString = 'https://user:pass@example.com:8080/path/to/resource?param1=value1¶m2=value2#section1';
    $uri = new Uri($uriString);

    expect($uri->getScheme())->toBe('https');
    expect($uri->getUserInfo())->toBe('user:pass');
    expect($uri->getHost())->toBe('example.com');
    expect($uri->getPort())->toBe(8080);
    expect($uri->getPath())->toBe('/path/to/resource');
    expect($uri->getQuery())->toBe('param1=value1¶m2=value2');
    expect($uri->getFragment())->toBe('section1');
    expect($uri->getAuthority())->toBe('user:pass@example.com:8080');
});

test('handles URIs with default ports correctly', function () {
    $uriHttp = new Uri('http://example.com:80/path');
    expect($uriHttp->getPort())->toBe(80);
    expect($uriHttp->getAuthority())->toBe('example.com');

    $uriHttps = new Uri('https://example.com:443/path');
    expect($uriHttps->getPort())->toBe(443);
    expect($uriHttps->getAuthority())->toBe('example.com');
});

test('handles schemeless URIs', function () {
    $uri = new Uri('//example.com/path');
    expect($uri->getScheme())->toBe('');
    expect($uri->getHost())->toBe('example.com');
});

test('handles path-only URIs', function () {
    $uri = new Uri('/just/a/path');
    expect($uri->getScheme())->toBe('');
    expect($uri->getHost())->toBe('');
    expect($uri->getPath())->toBe('/just/a/path');
});

test('throws exception for invalid URI', function () {
    new Uri('http://:');
})->throws(InvalidArgumentException::class, 'Invalid URI: http://:');

test('instances are immutable', function () {
    $originalUri = new Uri('https://example.com');

    $newUri = $originalUri->withScheme('ftp');

    expect($newUri)->not->toBe($originalUri);
    expect($newUri->getScheme())->toBe('ftp');

    expect($originalUri->getScheme())->toBe('https');
});

test('with... methods work for all components', function () {
    $uri = new Uri('');

    $newUri = $uri
        ->withScheme('https')
        ->withHost('example.org')
        ->withPort(9000)
        ->withUserInfo('testuser', 'testpass')
        ->withPath('/api/v1')
        ->withQuery('sort=asc')
        ->withFragment('results');

    expect($newUri->getScheme())->toBe('https');
    expect($newUri->getHost())->toBe('example.org');
    expect($newUri->getPort())->toBe(9000);
    expect($newUri->getUserInfo())->toBe('testuser:testpass');
    expect($newUri->getPath())->toBe('/api/v1');
    expect($newUri->getQuery())->toBe('sort=asc');
    expect($newUri->getFragment())->toBe('results');
});

test('__toString reassembles the URI correctly', function () {
    $expectedUri = 'ftp://admin:secret@sub.domain.co.uk:999/some/path?key=val#frag';

    $uri = new Uri('http://example.com');

    $reassembledUri = $uri
        ->withScheme('ftp')
        ->withHost('sub.domain.co.uk')
        ->withPort(999)
        ->withUserInfo('admin', 'secret')
        ->withPath('/some/path')
        ->withQuery('key=val')
        ->withFragment('frag');

    expect((string) $reassembledUri)->toBe($expectedUri);
});

test('__toString omits empty components', function () {
    $uri = new Uri('');
    $uri = $uri->withScheme('https')->withHost('example.com')->withPath('/test');

    expect((string) $uri)->toBe('https://example.com/test');
});
