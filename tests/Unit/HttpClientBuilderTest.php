<?php

declare(strict_types=1);

use Hibla\HttpClient\CookieJar;
use Hibla\HttpClient\HttpClient;
use Hibla\HttpClient\Interfaces\RequestInterface as Request;
use Hibla\HttpClient\Interfaces\ResponseInterface as Response;
use Hibla\HttpClient\ValueObjects\ProxyConfig;
use Hibla\HttpClient\ValueObjects\RetryConfig;

describe('Request Builder: Core Configuration', function () {
    it('sets timeout and connect timeout', function () {
        $r1 = new HttpClient();
        $r2 = $r1->timeout(15)->connectTimeout(5);

        expect(getPrivateProperty($r1, 'timeout'))->toBe(30);
        expect(getPrivateProperty($r2, 'timeout'))->toBe(15);
        expect(getPrivateProperty($r2, 'connectTimeout'))->toBe(5);
    });

    it('sets redirect configuration', function () {
        $r1 = new HttpClient();
        $r2 = $r1->redirects(false, 10);

        expect(getPrivateProperty($r1, 'followRedirects'))->toBeTrue();
        expect(getPrivateProperty($r2, 'followRedirects'))->toBeFalse();
        expect(getPrivateProperty($r2, 'maxRedirects'))->toBe(10);
    });

    it('sets SSL verification', function () {
        $r1 = new HttpClient();
        $r2 = $r1->verifySSL(false);

        expect(getPrivateProperty($r1, 'verifySSL'))->toBeTrue();
        expect(getPrivateProperty($r2, 'verifySSL'))->toBeFalse();
    });

    it('sets the user agent', function () {
        $r1 = new HttpClient();
        $r2 = $r1->withUserAgent('Test Agent');

        $req1 = getPrivateProperty($r1, 'request');
        $req2 = getPrivateProperty($r2, 'request');

        expect($req1->getUserAgent())->not->toBe('Test Agent');
        expect($req2->getUserAgent())->toBe('Test Agent');
    });

    it('configures HTTP protocol version', function () {
        $r1 = (new HttpClient())->http1();
        expect(getPrivateProperty($r1, 'protocol'))->toBe('1.1');

        $r2 = (new HttpClient())->http2();
        expect(getPrivateProperty($r2, 'protocol'))->toBe('2.0');

        $r3 = (new HttpClient())->http3();
        expect(getPrivateProperty($r3, 'protocol'))->toBe('3.0');
    });
});

describe('Request Builder: Headers', function () {
    it('sets a single header with withHeader', function () {
        $request = (new HttpClient())->withHeader('X-Test', 'Value');
        expect($request->getHeaderLine('X-Test'))->toBe('Value');
    });

    it('sets multiple headers with withHeaders', function () {
        $request = (new HttpClient())->withHeaders(['X-First' => '1', 'X-Second' => '2']);
        expect($request->getHeaderLine('X-First'))->toBe('1');
        expect($request->getHeaderLine('X-Second'))->toBe('2');
    });

    it('sets content type header via contentType()', function () {
        $request = (new HttpClient())->contentType('application/xml');
        expect($request->getHeaderLine('Content-Type'))->toBe('application/xml');
    });

    it('sets accept header', function () {
        $request = (new HttpClient())->accept('application/json');
        expect($request->getHeaderLine('Accept'))->toBe('application/json');
    });

    it('sets JSON content type with asJson()', function () {
        $request = (new HttpClient())->asJson();
        expect($request->getHeaderLine('Content-Type'))->toBe('application/json');
    });

    it('sets Form content type with asForm()', function () {
        $request = (new HttpClient())->asForm();
        expect($request->getHeaderLine('Content-Type'))->toBe('application/x-www-form-urlencoded');
    });
});

describe('Request Builder: Body', function () {
    it('sets a raw string body', function () {
        $request = (new HttpClient())->body('raw content');
        expect($request->getBody()->getContents())->toBe('raw content');
    });

    it('sets a JSON body and the correct header', function () {
        $request = (new HttpClient())->withJson(['foo' => 'bar']);
        expect($request->getBody()->getContents())->toBe(json_encode(['foo' => 'bar']));
        expect($request->getHeaderLine('Content-Type'))->toBe('application/json');
    });

    it('sets a URL-encoded form body and the correct header', function () {
        $request = (new HttpClient())->withForm(['user' => 'test', 'pass' => '123']);
        expect($request->getBody()->getContents())->toBe('user=test&pass=123');
        expect($request->getHeaderLine('Content-Type'))->toBe('application/x-www-form-urlencoded');
    });

    it('sets multipart data and removes Content-Type header', function () {
        $request = (new HttpClient())
            ->contentType('should-be-removed')
            ->withMultipart(['field' => 'value'])
        ;

        $Request = getPrivateProperty($request, 'request');
        $options = $Request->getOptions();

        expect($request->hasHeader('Content-Type'))->toBeFalse();
        expect($options['multipart'])->toBe(['field' => 'value']);
    });
});

describe('Request Builder: Advanced Features', function () {
    it('configures retry settings', function () {
        $request = (new HttpClient())->retry(5, 2.0);
        $retryConfig = getPrivateProperty($request, 'retryConfig');
        expect($retryConfig)->toBeInstanceOf(RetryConfig::class);
        expect($retryConfig->maxRetries)->toBe(5);
        expect($retryConfig->baseDelay)->toBe(2.0);
    });

    it('configures an HTTP proxy', function () {
        $request = (new HttpClient())->withProxy('proxy.host', 8080);
        $proxyConfig = getPrivateProperty($request, 'proxyConfig');
        expect($proxyConfig)->toBeInstanceOf(ProxyConfig::class)
            ->and($proxyConfig->type)->toBe('http')
        ;
    });

    it('configures a SOCKS4 proxy', function () {
        $request = (new HttpClient())->withSocks4Proxy('proxy.host', 1080);
        $proxyConfig = getPrivateProperty($request, 'proxyConfig');
        expect($proxyConfig)->toBeInstanceOf(ProxyConfig::class)
            ->and($proxyConfig->type)->toBe('socks4')
        ;
    });

    it('configures a SOCKS5 proxy', function () {
        $request = (new HttpClient())->withSocks5Proxy('proxy.host', 1080);
        $proxyConfig = getPrivateProperty($request, 'proxyConfig');
        expect($proxyConfig)->toBeInstanceOf(ProxyConfig::class)
            ->and($proxyConfig->type)->toBe('socks5')
        ;
    });

    it('configures a custom cookie jar', function () {
        $jar = new CookieJar();
        $request = (new HttpClient())->useCookieJar($jar);

        expect($request->getCookieJar())->toBe($jar);
    });

    it('configures interceptors', function () {
        $reqInt = fn (Request $r) => $r;
        $resInt = fn (Response $r) => $r;
        $request = (new HttpClient())->withRequestInterceptor($reqInt)->withResponseInterceptor($resInt);

        $interceptors = getPrivateProperty($request, 'interceptors');
        expect($interceptors)->toHaveCount(2);
    });
});

describe('Request Builder: URI Template Expansion', function () {
    it('expands simple URI templates', function () {
        $request = (new HttpClient())->withUrlParameter('userId', '123');

        $reflection = new ReflectionClass(HttpClient::class);
        $method = $reflection->getMethod('expandUriTemplate');
        $expandedUrl = $method->invoke($request, '/users/{userId}/posts');

        expect($expandedUrl)->toBe('/users/123/posts');
    });

    it('expands multiple parameters', function () {
        $request = (new HttpClient())->withUrlParameters(['userId' => 123, 'postId' => 456]);

        $reflection = new ReflectionClass(HttpClient::class);
        $method = $reflection->getMethod('expandUriTemplate');
        $expandedUrl = $method->invoke($request, '/users/{userId}/posts/{postId}');

        expect($expandedUrl)->toBe('/users/123/posts/456');
    });

    it('URL-encodes simple parameters', function () {
        $request = (new HttpClient())->withUrlParameter('query', 'a space & stuff');

        $reflection = new ReflectionClass(HttpClient::class);
        $method = $reflection->getMethod('expandUriTemplate');
        $expandedUrl = $method->invoke($request, '/search/{query}');

        expect($expandedUrl)->toBe('/search/a%20space%20%26%20stuff');
    });

    it('does not encode reserved expansion parameters', function () {
        $request = (new HttpClient())->withUrlParameter('path', 'a/b/c');

        $reflection = new ReflectionClass(HttpClient::class);
        $method = $reflection->getMethod('expandUriTemplate');
        $expandedUrl = $method->invoke($request, '/files/{+path}');

        expect($expandedUrl)->toBe('/files/a/b/c');
    });

    it('ignores missing parameters', function () {
        $request = new HttpClient();

        $reflection = new ReflectionClass(HttpClient::class);
        $method = $reflection->getMethod('expandUriTemplate');
        $expandedUrl = $method->invoke($request, '/users/{userId}/posts');

        expect($expandedUrl)->toBe('/users/{userId}/posts');
    });
});
