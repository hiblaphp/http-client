<?php

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\Request;

beforeEach(function () {
    Http::startTesting();
    Http::mock()->url('*')->persistent()->respondWith('OK')->register();
});

afterEach(function () {
    Http::stopTesting();
});

describe('Request Class Configuration', function () {

    it('sets basic options like timeout and user agent', function () {
        Http::request()
            ->timeout(15)
            ->connectTimeout(5)
            ->withUserAgent('MyTestAgent/1.0')
            ->get('/test')
            ->await();

        $request = Http::getLastRequest();
        $options = $request->getOptions();

        expect($options[CURLOPT_TIMEOUT])->toBe(15);
        expect($options[CURLOPT_CONNECTTIMEOUT])->toBe(5);
        expect($options[CURLOPT_USERAGENT])->toBe('MyTestAgent/1.0');
    });

    it('configures redirects', function () {
        Http::request()->redirects(false)->get('/test')->await();
        $options1 = Http::getLastRequest()->getOptions();
        expect($options1[CURLOPT_FOLLOWLOCATION])->toBeFalse();

        Http::request()->redirects(true, 10)->get('/test')->await();
        $options2 = Http::getLastRequest()->getOptions();
        expect($options2[CURLOPT_FOLLOWLOCATION])->toBeTrue();
        expect($options2[CURLOPT_MAXREDIRS])->toBe(10);
    });

    it('configures SSL verification', function () {
        Http::request()->verifySSL(false)->get('/test')->await();
        $options = Http::getLastRequest()->getOptions();

        expect($options[CURLOPT_SSL_VERIFYPEER])->toBeFalse();
        expect($options[CURLOPT_SSL_VERIFYHOST])->toBe(0);
    });

    it('configures HTTP protocol version', function () {
        Http::request()->http1()->get('/test')->await();
        expect(Http::getLastRequest()->getOptions()[CURLOPT_HTTP_VERSION])->toBe(CURL_HTTP_VERSION_1_1);

        Http::request()->http2()->get('/test')->await();
        expect(Http::getLastRequest()->getOptions()[CURLOPT_HTTP_VERSION])->toBe(CURL_HTTP_VERSION_2TLS);
    });
});

describe('Request Body Helpers', function () {

    it('correctly sets a raw string body', function () {
        Http::request()->body('<xml>data</xml>')->contentType('application/xml')->post('/test')->await();

        $request = Http::getLastRequest();
        expect($request->getBody())->toBe('<xml>data</xml>');
        expect($request->getHeaderLine('Content-Type'))->toBe('application/xml');
    });

    it('correctly sets a JSON body', function () {
        Http::request()->withJson(['user' => 'test'])->post('/test')->await();

        $request = Http::getLastRequest();
        expect($request->getBody())->toBe(json_encode(['user' => 'test']));
        expect($request->getHeaderLine('Content-Type'))->toBe('application/json');
    });

    it('correctly sets a URL-encoded form body', function () {
        Http::request()->withForm(['user' => 'test', 'pass' => '123'])->post('/test')->await();

        $request = Http::getLastRequest();
        expect($request->getBody())->toBe('user=test&pass=123');
        expect($request->getHeaderLine('Content-Type'))->toBe('application/x-www-form-urlencoded');
    });
});

describe('Authentication Methods', function () {

    it('configures a bearer token', function () {
        Http::request()->withToken('my-secret-token')->get('/test')->await();
        Http::assertBearerTokenSent('my-secret-token');
        expect(true)->toBeTrue();
    });

    it('configures basic authentication', function () {
        Http::request()->withBasicAuth('user', 'pass')->get('/test')->await();
        $options = Http::getLastRequest()->getOptions();

        expect($options[CURLOPT_HTTPAUTH])->toBe(CURLAUTH_BASIC);
        expect($options[CURLOPT_USERPWD])->toBe('user:pass');
    });

    it('configures digest authentication', function () {
        Http::request()->withDigestAuth('user', 'pass')->get('/test')->await();
        $options = Http::getLastRequest()->getOptions();

        expect($options[CURLOPT_HTTPAUTH])->toBe(CURLAUTH_DIGEST);
        expect($options[CURLOPT_USERPWD])->toBe('user:pass');
    });
});

describe('Cookie Helpers', function () {

    it('adds a single cookie via withCookie', function () {
        Http::request()->withCookie('name', 'value')->get('/test')->await();
        Http::assertHeaderSent('Cookie', 'name=value');
        expect(true)->toBeTrue();
    });

    it('chains multiple cookies correctly', function () {
        Http::request()
            ->withCookie('c1', 'v1')
            ->withCookie('c2', 'v2')
            ->get('/test')
            ->await();

        Http::assertHeaderSent('Cookie', 'c1=v1; c2=v2');
        expect(true)->toBeTrue();
    });
});

describe('Interceptors', function () {

    it('applies request interceptors before sending', function () {
        Http::request()
            ->interceptRequest(function (Request $request) {
                return $request->withHeader('X-Interceptor', 'Applied');
            })
            ->get('/test')
            ->await();

        Http::assertHeaderSent('X-Interceptor', 'Applied');
        expect(true)->toBeTrue();
    });

    it('applies response interceptors after receiving', function () {
        $response = Http::request()
            ->interceptResponse(function (Response $response) {
                return $response->withHeader('X-Response-Interceptor', 'Modified');
            })
            ->get('/test')
            ->await();

        expect($response->header('X-Response-Interceptor'))->toBe('Modified');
    });
});
