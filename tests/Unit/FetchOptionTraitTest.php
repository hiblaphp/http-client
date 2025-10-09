<?php

use Hibla\HttpClient\CacheConfig;
use Hibla\HttpClient\Cookie;
use Hibla\HttpClient\CookieJar;
use Hibla\HttpClient\ProxyConfig;
use Hibla\HttpClient\RetryConfig;
use Hibla\HttpClient\Traits\FetchOptionTrait;

class FetchOptionTraitTestClass
{
    use FetchOptionTrait;
}

describe('FetchOptionTrait: Basic Option Normalization', function () {
    it('normalizes basic GET request options', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = [
            'method' => 'GET',
            'headers' => [
                'X-Custom' => 'value',
                'Accept' => 'application/json',
            ],
        ];

        $result = $trait->normalizeFetchOptions('https://example.com/api', $options);

        expect($result[CURLOPT_URL])->toBe('https://example.com/api');
        expect($result[CURLOPT_CUSTOMREQUEST])->toBe('GET');
        expect($result[CURLOPT_RETURNTRANSFER])->toBeTrue();
        expect($result[CURLOPT_HEADER])->toBeTrue();
        expect($result[CURLOPT_HTTPHEADER])->toBeArray();
        expect($result[CURLOPT_HTTPHEADER])->toContain('X-Custom: value');
        expect($result[CURLOPT_HTTPHEADER])->toContain('Accept: application/json');
    });

    it('normalizes POST request with JSON body', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = [
            'method' => 'POST',
            'json' => ['key' => 'value', 'number' => 123],
        ];

        $result = $trait->normalizeFetchOptions('https://example.com/api', $options);

        expect($result[CURLOPT_CUSTOMREQUEST])->toBe('POST');
        expect($result[CURLOPT_POSTFIELDS])->toBe('{"key":"value","number":123}');
        expect($result[CURLOPT_HTTPHEADER])->toContain('Content-Type: application/json');
    });

    it('normalizes POST request with form data', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = [
            'method' => 'POST',
            'form' => ['key' => 'value', 'foo' => 'bar'],
        ];

        $result = $trait->normalizeFetchOptions('https://example.com/api', $options);

        expect($result[CURLOPT_CUSTOMREQUEST])->toBe('POST');
        expect($result[CURLOPT_POSTFIELDS])->toBe('key=value&foo=bar');
        expect($result[CURLOPT_HTTPHEADER])->toContain('Content-Type: application/x-www-form-urlencoded');
    });

    it('normalizes request with raw body', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = [
            'method' => 'POST',
            'body' => 'raw string data',
        ];

        $result = $trait->normalizeFetchOptions('https://example.com/api', $options);

        expect($result[CURLOPT_POSTFIELDS])->toBe('raw string data');
    });
});

describe('FetchOptionTrait: Timeout Configuration', function () {
    it('sets timeout options', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = [
            'timeout' => 30,
            'connect_timeout' => 5,
        ];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        expect($result[CURLOPT_TIMEOUT])->toBe(30);
        expect($result[CURLOPT_CONNECTTIMEOUT])->toBe(5);
    });

    it('converts string timeout to integer', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = [
            'timeout' => '45',
            'connect_timeout' => '10',
        ];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        expect($result[CURLOPT_TIMEOUT])->toBe(45);
        expect($result[CURLOPT_CONNECTTIMEOUT])->toBe(10);
    });
});

describe('FetchOptionTrait: Redirect Configuration', function () {
    it('enables follow redirects', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = [
            'follow_redirects' => true,
            'max_redirects' => 10,
        ];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        expect($result[CURLOPT_FOLLOWLOCATION])->toBeTrue();
        expect($result[CURLOPT_MAXREDIRS])->toBe(10);
    });

    it('disables follow redirects', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = ['follow_redirects' => false];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        expect($result[CURLOPT_FOLLOWLOCATION])->toBeFalse();
    });
});

describe('FetchOptionTrait: SSL Verification', function () {
    it('enables SSL verification', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = ['verify_ssl' => true];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        expect($result[CURLOPT_SSL_VERIFYPEER])->toBeTrue();
        expect($result[CURLOPT_SSL_VERIFYHOST])->toBe(2);
    });

    it('disables SSL verification', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = ['verify_ssl' => false];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        expect($result[CURLOPT_SSL_VERIFYPEER])->toBeFalse();
        expect($result[CURLOPT_SSL_VERIFYHOST])->toBe(0);
    });
});

describe('FetchOptionTrait: User Agent', function () {
    it('sets custom user agent', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = ['user_agent' => 'MyCustomAgent/1.0'];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        expect($result[CURLOPT_USERAGENT])->toBe('MyCustomAgent/1.0');
    });
});

describe('FetchOptionTrait: HTTP Version', function () {
    it('sets HTTP version from http_version option', function () {
        $trait = new FetchOptionTraitTestClass();

        $testCases = [
            ['1.0', CURL_HTTP_VERSION_1_0],
            ['1.1', CURL_HTTP_VERSION_1_1],
            ['2.0', CURL_HTTP_VERSION_2TLS],
            ['2', CURL_HTTP_VERSION_2TLS],
        ];

        foreach ($testCases as [$version, $expected]) {
            $result = $trait->normalizeFetchOptions('https://example.com', ['http_version' => $version]);
            expect($result[CURLOPT_HTTP_VERSION])->toBe($expected);
        }
    });

    it('sets HTTP version from protocol option', function () {
        $trait = new FetchOptionTraitTestClass();

        $result = $trait->normalizeFetchOptions('https://example.com', ['protocol' => '2.0']);

        expect($result[CURLOPT_HTTP_VERSION])->toBe(CURL_HTTP_VERSION_2TLS);
    });
});

describe('FetchOptionTrait: Authentication', function () {
    it('configures bearer token authentication', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = [
            'auth' => [
                'bearer' => 'my-secret-token',
            ],
        ];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        expect($result[CURLOPT_HTTPHEADER])->toContain('Authorization: Bearer my-secret-token');
    });

    it('configures basic authentication', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = [
            'auth' => [
                'basic' => [
                    'username' => 'user',
                    'password' => 'pass',
                ],
            ],
        ];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        expect($result[CURLOPT_USERPWD])->toBe('user:pass');
        expect($result[CURLOPT_HTTPAUTH])->toBe(CURLAUTH_BASIC);
    });

    it('configures digest authentication', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = [
            'auth' => [
                'digest' => [
                    'username' => 'user',
                    'password' => 'pass',
                ],
            ],
        ];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        expect($result[CURLOPT_USERPWD])->toBe('user:pass');
        expect($result[CURLOPT_HTTPAUTH])->toBe(CURLAUTH_DIGEST);
    });
});

describe('FetchOptionTrait: Proxy Configuration', function () {
    it('extracts proxy config from ProxyConfig object', function () {
        $trait = new FetchOptionTraitTestClass();

        $proxyConfig = new ProxyConfig('proxy.example.com', 8080);
        $options = ['proxy' => $proxyConfig];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        expect($result[CURLOPT_PROXY])->toBe('proxy.example.com:8080');
        expect($result[CURLOPT_HTTPPROXYTUNNEL])->toBeTrue();
    });

    it('extracts proxy config from array', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = [
            'proxy' => [
                'host' => 'proxy.example.com',
                'port' => 3128,
                'username' => 'user',
                'password' => 'pass',
            ],
        ];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        expect($result[CURLOPT_PROXY])->toBe('proxy.example.com:3128');
        expect($result[CURLOPT_PROXYUSERPWD])->toBe('user:pass');
    });

    it('extracts proxy config from URL string', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = ['proxy' => 'http://user:pass@proxy.example.com:8080'];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        expect($result[CURLOPT_PROXY])->toBe('proxy.example.com:8080');
        expect($result[CURLOPT_PROXYUSERPWD])->toBe('user:pass');
    });

    it('configures SOCKS proxy without tunneling', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = [
            'proxy' => [
                'host' => 'socks.example.com',
                'port' => 1080,
                'type' => 'socks5',
            ],
        ];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        expect($result[CURLOPT_HTTPPROXYTUNNEL])->toBeFalse();
    });

    it('uses default port when not specified', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = [
            'proxy' => [
                'host' => 'proxy.example.com',
            ],
        ];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        expect($result[CURLOPT_PROXY])->toBe('proxy.example.com:8080');
    });
});

describe('FetchOptionTrait: Cookie Handling', function () {
    it('applies cookies from cookie jar', function () {
        $trait = new FetchOptionTraitTestClass();

        $jar = new CookieJar();
        $cookie = new Cookie(
            name: 'session',
            value: 'abc123',
            domain: 'example.com',
            path: '/'
        );
        $jar->setCookie($cookie);

        $options = ['cookie_jar' => $jar];

        $result = $trait->normalizeFetchOptions('https://example.com/api', $options);

        expect($result[CURLOPT_HTTPHEADER])->toContain('Cookie: session=abc123');
        expect($result['_cookie_jar'])->toBe($jar);
    });

    it('applies cookies from array', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = [
            'cookies' => [
                'session' => 'abc123',
                'user' => 'john',
            ],
        ];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        $cookieHeader = null;
        foreach ($result[CURLOPT_HTTPHEADER] as $header) {
            if (stripos($header, 'Cookie:') === 0) {
                $cookieHeader = $header;

                break;
            }
        }

        expect($cookieHeader)->not->toBeNull();
        expect($cookieHeader)->toContain('session=');
        expect($cookieHeader)->toContain('user=');
    });

    it('applies cookie from string', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = ['cookie' => 'session=abc123; user=john'];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        expect($result[CURLOPT_HTTPHEADER])->toContain('Cookie: session=abc123; user=john');
    });

    it('removes existing cookie headers before adding new ones', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = [
            'headers' => [
                'Cookie' => 'old=value',
                'X-Custom' => 'header',
            ],
            'cookies' => ['new' => 'value'],
        ];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        $cookieHeaders = array_filter($result[CURLOPT_HTTPHEADER], function ($header) {
            return stripos($header, 'Cookie:') === 0;
        });

        expect(count($cookieHeaders))->toBe(1);
        expect($result[CURLOPT_HTTPHEADER])->toContain('X-Custom: header');
    });
});

describe('FetchOptionTrait: SSE Headers', function () {
    it('ensures SSE headers when flag is true', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = ['method' => 'GET'];

        $result = $trait->normalizeFetchOptions('https://example.com/events', $options, true);

        expect($result[CURLOPT_HTTPHEADER])->toContain('Accept: text/event-stream');
        expect($result[CURLOPT_HTTPHEADER])->toContain('Cache-Control: no-cache');
    });

    it('does not add duplicate SSE headers', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = [
            'headers' => [
                'Accept' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
            ],
        ];

        $result = $trait->normalizeFetchOptions('https://example.com/events', $options, true);

        $acceptCount = 0;
        $cacheCount = 0;

        foreach ($result[CURLOPT_HTTPHEADER] as $header) {
            if (stripos($header, 'Accept:') === 0) {
                $acceptCount++;
            }
            if (stripos($header, 'Cache-Control:') === 0) {
                $cacheCount++;
            }
        }

        expect($acceptCount)->toBe(1);
        expect($cacheCount)->toBe(1);
    });
});

describe('FetchOptionTrait: cURL Options Format', function () {
    it('recognizes and preserves cURL options format', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = [
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['X-Custom: value'],
        ];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        expect($result[CURLOPT_CUSTOMREQUEST])->toBe('GET');
        expect($result[CURLOPT_TIMEOUT])->toBe(30);
        expect($result[CURLOPT_HTTPHEADER])->toContain('X-Custom: value');
    });

    it('adds default cURL options when using cURL format', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = [CURLOPT_CUSTOMREQUEST => 'GET'];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        expect($result[CURLOPT_URL])->toBe('https://example.com');
        expect($result[CURLOPT_RETURNTRANSFER])->toBeTrue();
        expect($result[CURLOPT_HEADER])->toBeTrue();
        expect($result[CURLOPT_NOBODY])->toBeFalse();
    });
});

describe('FetchOptionTrait: Special Options Filtering', function () {
    it('filters out special fetch options', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = [
            'method' => 'GET',
            'stream' => true,
            'retry' => true,
            'cache' => true,
            'sse' => true,
            'on_chunk' => function () {},
            'on_event' => function () {},
        ];

        $result = $trait->normalizeFetchOptions('https://example.com', $options);

        expect(isset($result['stream']))->toBeFalse();
        expect(isset($result['retry']))->toBeFalse();
        expect(isset($result['cache']))->toBeFalse();
        expect(isset($result['sse']))->toBeFalse();
        expect(isset($result['on_chunk']))->toBeFalse();
        expect(isset($result['on_event']))->toBeFalse();
    });
});

describe('FetchOptionTrait: Private Method - Extract Cache Config', function () {
    it('extracts cache config from boolean true', function () {
        $trait = new FetchOptionTraitTestClass();

        $config = callPrivateMethod($trait, 'extractCacheConfig', [['cache' => true]]);

        expect($config)->toBeInstanceOf(CacheConfig::class);
        expect($config->ttlSeconds)->toBe(3600);
    });

    it('extracts cache config from CacheConfig object', function () {
        $trait = new FetchOptionTraitTestClass();

        $cacheConfig = new CacheConfig(7200);
        $config = callPrivateMethod($trait, 'extractCacheConfig', [['cache' => $cacheConfig]]);

        expect($config)->toBe($cacheConfig);
    });

    it('extracts cache config from integer TTL', function () {
        $trait = new FetchOptionTraitTestClass();

        $config = callPrivateMethod($trait, 'extractCacheConfig', [['cache' => 1800]]);

        expect($config)->toBeInstanceOf(CacheConfig::class);
        expect($config->ttlSeconds)->toBe(1800);
    });

    it('extracts cache config from array', function () {
        $trait = new FetchOptionTraitTestClass();

        $config = callPrivateMethod($trait, 'extractCacheConfig', [[
            'cache' => [
                'ttl' => 3600,
                'respect_server_headers' => false,
            ],
        ]]);

        expect($config)->toBeInstanceOf(CacheConfig::class);
        expect($config->ttlSeconds)->toBe(3600);
        expect($config->respectServerHeaders)->toBeFalse();
    });

    it('returns null when cache is not set', function () {
        $trait = new FetchOptionTraitTestClass();

        $config = callPrivateMethod($trait, 'extractCacheConfig', [[]]);

        expect($config)->toBeNull();
    });
});

describe('FetchOptionTrait: Private Method - Extract Retry Config', function () {
    it('extracts retry config from boolean true', function () {
        $trait = new FetchOptionTraitTestClass();

        $config = callPrivateMethod($trait, 'extractRetryConfig', [['retry' => true]]);

        expect($config)->toBeInstanceOf(RetryConfig::class);
        expect($config->maxRetries)->toBe(3);
    });

    it('extracts retry config from RetryConfig object', function () {
        $trait = new FetchOptionTraitTestClass();

        $retryConfig = new RetryConfig(5, 2.0);
        $config = callPrivateMethod($trait, 'extractRetryConfig', [['retry' => $retryConfig]]);

        expect($config)->toBe($retryConfig);
    });

    it('extracts retry config from array', function () {
        $trait = new FetchOptionTraitTestClass();

        $config = callPrivateMethod($trait, 'extractRetryConfig', [[
            'retry' => [
                'max_retries' => 5,
                'base_delay' => 2.0,
                'max_delay' => 30.0,
                'backoff_multiplier' => 3.0,
                'jitter' => false,
            ],
        ]]);

        expect($config)->toBeInstanceOf(RetryConfig::class);
        expect($config->maxRetries)->toBe(5);
        expect($config->baseDelay)->toBe(2.0);
        expect($config->maxDelay)->toBe(30.0);
        expect($config->backoffMultiplier)->toBe(3.0);
        expect($config->jitter)->toBeFalse();
    });

    it('returns null when retry is not set', function () {
        $trait = new FetchOptionTraitTestClass();

        $config = callPrivateMethod($trait, 'extractRetryConfig', [[]]);

        expect($config)->toBeNull();
    });
});

describe('FetchOptionTrait: Private Method - isCurlOptionsFormat', function () {
    it('identifies cURL options format', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = [CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => 'GET'];

        $isCurl = callPrivateMethod($trait, 'isCurlOptionsFormat', [$options]);

        expect($isCurl)->toBeTrue();
    });

    it('identifies non-cURL options format', function () {
        $trait = new FetchOptionTraitTestClass();

        $options = ['method' => 'GET', 'timeout' => 30];

        $isCurl = callPrivateMethod($trait, 'isCurlOptionsFormat', [$options]);

        expect($isCurl)->toBeFalse();
    });
});
