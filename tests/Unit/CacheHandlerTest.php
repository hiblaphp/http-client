<?php

use Hibla\HttpClient\CacheConfig;
use Hibla\HttpClient\Handlers\CacheHandler;
use Hibla\HttpClient\Handlers\RequestExecutorHandler;
use Hibla\HttpClient\Handlers\RetryHandler;
use Hibla\HttpClient\Response;
use Psr\Http\Message\StreamInterface;
use Psr\SimpleCache\CacheInterface;

describe('CacheHandler: Cache Key Generation', function () {
    it('generates consistent cache keys for the same URL', function () {
        $handler = new CacheHandler();

        $key1 = callPrivateMethod($handler, 'generateCacheKey', ['https://example.com/api']);
        $key2 = callPrivateMethod($handler, 'generateCacheKey', ['https://example.com/api']);

        expect($key1)->toBe($key2);
        expect($key1)->toStartWith('http_');
    });

    it('generates different cache keys for different URLs', function () {
        $handler = new CacheHandler();

        $key1 = callPrivateMethod($handler, 'generateCacheKey', ['https://example.com/api/v1']);
        $key2 = callPrivateMethod($handler, 'generateCacheKey', ['https://example.com/api/v2']);

        expect($key1)->not->toBe($key2);
    });
});

describe('CacheHandler: Cache Item Validation', function () {
    it('validates a properly structured cached item', function () {
        $handler = new CacheHandler();

        $cachedItem = [
            'body' => 'test body',
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'expires_at' => time() + 3600,
        ];

        $isValid = callPrivateMethod($handler, 'isCachedItemValid', [$cachedItem]);
        expect($isValid)->toBeTrue();
    });

    it('invalidates an expired cached item', function () {
        $handler = new CacheHandler();

        $cachedItem = [
            'body' => 'test body',
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'expires_at' => time() - 3600, // Expired
        ];

        $isValid = callPrivateMethod($handler, 'isCachedItemValid', [$cachedItem]);
        expect($isValid)->toBeFalse();
    });

    it('invalidates a cached item with missing fields', function () {
        $handler = new CacheHandler();

        $cachedItem = [
            'body' => 'test body',
            'status' => 200,
            // Missing 'headers' and 'expires_at'
        ];

        $isValid = callPrivateMethod($handler, 'isCachedItemValid', [$cachedItem]);
        expect($isValid)->toBeFalse();
    });

    it('invalidates a cached item with wrong types', function () {
        $handler = new CacheHandler();

        $cachedItem = [
            'body' => 'test body',
            'status' => '200',
            'headers' => ['Content-Type' => 'application/json'],
            'expires_at' => time() + 3600,
        ];

        $isValid = callPrivateMethod($handler, 'isCachedItemValid', [$cachedItem]);
        expect($isValid)->toBeFalse();
    });

    it('invalidates non-array input', function () {
        $handler = new CacheHandler();

        $isValid = callPrivateMethod($handler, 'isCachedItemValid', [null]);
        expect($isValid)->toBeFalse();

        $isValid = callPrivateMethod($handler, 'isCachedItemValid', ['string']);
        expect($isValid)->toBeFalse();
    });
});

describe('CacheHandler: Header Value Extraction', function () {
    it('extracts string header values', function () {
        $handler = new CacheHandler();

        $value = callPrivateMethod($handler, 'extractHeaderValue', ['some-value']);
        expect($value)->toBe('some-value');
    });

    it('extracts first value from array headers', function () {
        $handler = new CacheHandler();

        $value = callPrivateMethod($handler, 'extractHeaderValue', [['first', 'second']]);
        expect($value)->toBe('first');
    });

    it('returns null for invalid header values', function () {
        $handler = new CacheHandler();

        $value = callPrivateMethod($handler, 'extractHeaderValue', [null]);
        expect($value)->toBeNull();

        $value = callPrivateMethod($handler, 'extractHeaderValue', [[]]);
        expect($value)->toBeNull();

        $value = callPrivateMethod($handler, 'extractHeaderValue', [[123]]);
        expect($value)->toBeNull();
    });
});

describe('CacheHandler: Conditional Headers', function () {
    it('adds If-None-Match header when ETag exists', function () {
        $handler = new CacheHandler();

        $curlOptions = [CURLOPT_HTTPHEADER => []];
        $cachedItem = [
            'headers' => [
                'etag' => '"abc123"',
            ],
        ];

        $result = callPrivateMethod($handler, 'addConditionalHeaders', [$curlOptions, $cachedItem]);

        expect($result[CURLOPT_HTTPHEADER])->toContain('If-None-Match: "abc123"');
    });

    it('adds If-Modified-Since header when Last-Modified exists', function () {
        $handler = new CacheHandler();

        $curlOptions = [CURLOPT_HTTPHEADER => []];
        $cachedItem = [
            'headers' => [
                'last-modified' => 'Wed, 21 Oct 2015 07:28:00 GMT',
            ],
        ];

        $result = callPrivateMethod($handler, 'addConditionalHeaders', [$curlOptions, $cachedItem]);

        expect($result[CURLOPT_HTTPHEADER])->toContain('If-Modified-Since: Wed, 21 Oct 2015 07:28:00 GMT');
    });

    it('adds both conditional headers when both exist', function () {
        $handler = new CacheHandler();

        $curlOptions = [CURLOPT_HTTPHEADER => []];
        $cachedItem = [
            'headers' => [
                'etag' => '"abc123"',
                'last-modified' => 'Wed, 21 Oct 2015 07:28:00 GMT',
            ],
        ];

        $result = callPrivateMethod($handler, 'addConditionalHeaders', [$curlOptions, $cachedItem]);

        expect($result[CURLOPT_HTTPHEADER])->toContain('If-None-Match: "abc123"');
        expect($result[CURLOPT_HTTPHEADER])->toContain('If-Modified-Since: Wed, 21 Oct 2015 07:28:00 GMT');
    });

    it('preserves existing headers', function () {
        $handler = new CacheHandler();

        $curlOptions = [CURLOPT_HTTPHEADER => ['X-Custom: value']];
        $cachedItem = [
            'headers' => [
                'etag' => '"abc123"',
            ],
        ];

        $result = callPrivateMethod($handler, 'addConditionalHeaders', [$curlOptions, $cachedItem]);

        expect($result[CURLOPT_HTTPHEADER])->toContain('X-Custom: value');
        expect($result[CURLOPT_HTTPHEADER])->toContain('If-None-Match: "abc123"');
    });

    it('handles array header values', function () {
        $handler = new CacheHandler();

        $curlOptions = [CURLOPT_HTTPHEADER => []];
        $cachedItem = [
            'headers' => [
                'etag' => ['"abc123"', '"def456"'],
            ],
        ];

        $result = callPrivateMethod($handler, 'addConditionalHeaders', [$curlOptions, $cachedItem]);

        expect($result[CURLOPT_HTTPHEADER])->toContain('If-None-Match: "abc123"');
    });

    it('returns original options when cached item has no headers', function () {
        $handler = new CacheHandler();

        $curlOptions = [CURLOPT_HTTPHEADER => ['X-Custom: value']];
        $cachedItem = [];

        $result = callPrivateMethod($handler, 'addConditionalHeaders', [$curlOptions, $cachedItem]);

        expect($result)->toBe($curlOptions);
    });
});

describe('CacheHandler: Expiry Calculation', function () {
    it('calculates expiry from Cache-Control max-age header', function () {
        $handler = new CacheHandler();

        $bodyMock = Mockery::mock(StreamInterface::class);
        $bodyMock->shouldReceive('__toString')->andReturn('test');

        $response = new Response('test', 200, ['Cache-Control' => 'max-age=3600']);
        $cacheConfig = new CacheConfig(ttlSeconds: 1800, respectServerHeaders: true);

        $expiry = callPrivateMethod($handler, 'calculateExpiry', [$response, $cacheConfig]);

        $expectedExpiry = time() + 3600;
        expect($expiry)->toBeGreaterThanOrEqual($expectedExpiry - 1);
        expect($expiry)->toBeLessThanOrEqual($expectedExpiry + 1);
    });

    it('uses config TTL when respectServerHeaders is false', function () {
        $handler = new CacheHandler();

        $response = new Response('test', 200, ['Cache-Control' => 'max-age=3600']);
        $cacheConfig = new CacheConfig(ttlSeconds: 1800, respectServerHeaders: false);

        $expiry = callPrivateMethod($handler, 'calculateExpiry', [$response, $cacheConfig]);

        $expectedExpiry = time() + 1800;
        expect($expiry)->toBeGreaterThanOrEqual($expectedExpiry - 1);
        expect($expiry)->toBeLessThanOrEqual($expectedExpiry + 1);
    });

    it('uses config TTL when Cache-Control header is missing', function () {
        $handler = new CacheHandler();

        $response = new Response('test', 200, []);
        $cacheConfig = new CacheConfig(ttlSeconds: 1800, respectServerHeaders: true);

        $expiry = callPrivateMethod($handler, 'calculateExpiry', [$response, $cacheConfig]);

        $expectedExpiry = time() + 1800;
        expect($expiry)->toBeGreaterThanOrEqual($expectedExpiry - 1);
        expect($expiry)->toBeLessThanOrEqual($expectedExpiry + 1);
    });

    it('handles Cache-Control with multiple directives', function () {
        $handler = new CacheHandler();

        $response = new Response('test', 200, ['Cache-Control' => 'public, max-age=7200, must-revalidate']);
        $cacheConfig = new CacheConfig(ttlSeconds: 1800, respectServerHeaders: true);

        $expiry = callPrivateMethod($handler, 'calculateExpiry', [$response, $cacheConfig]);

        $expectedExpiry = time() + 7200;
        expect($expiry)->toBeGreaterThanOrEqual($expectedExpiry - 1);
        expect($expiry)->toBeLessThanOrEqual($expectedExpiry + 1);
    });
});

describe('CacheHandler: Cache Response', function () {
    it('caches a response with valid expiry', function () {
        $cacheMock = Mockery::mock(CacheInterface::class);
        $handler = new CacheHandler();

        $response = new Response('test body', 200, ['Content-Type' => 'application/json']);
        $cacheConfig = new CacheConfig(ttlSeconds: 3600);

        $cacheMock->shouldReceive('set')
            ->once()
            ->withArgs(function ($key, $value, $ttl) {
                return is_string($key)
                    && is_array($value)
                    && isset($value['body'], $value['status'], $value['headers'], $value['expires_at'])
                    && $value['body'] === 'test body'
                    && $value['status'] === 200
                    && is_int($ttl)
                    && $ttl > 0;
            })
        ;

        callPrivateMethod($handler, 'cacheResponse', [
            $response,
            $cacheMock,
            'test_key',
            $cacheConfig,
        ]);
    });

    it('does not cache when expiry is in the past', function () {
        $cacheMock = Mockery::mock(CacheInterface::class);
        $handler = new CacheHandler();

        $response = new Response('test body', 200, []);
        // Create a config that will result in past expiry
        $cacheConfig = new CacheConfig(ttlSeconds: -100);

        $cacheMock->shouldNotReceive('set');

        callPrivateMethod($handler, 'cacheResponse', [
            $response,
            $cacheMock,
            'test_key',
            $cacheConfig,
        ]);
    });
});

describe('CacheHandler: Handle Not Modified', function () {
    it('returns cached response on 304 and updates expiry', function () {
        $cacheMock = Mockery::mock(CacheInterface::class);
        $handler = new CacheHandler();

        $response304 = new Response('', 304, []);
        $cachedItem = [
            'body' => 'cached body',
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'expires_at' => time() - 100,
        ];
        $cacheConfig = new CacheConfig(ttlSeconds: 3600);

        $cacheMock->shouldReceive('set')
            ->once()
            ->withArgs(function ($key, $value, $ttl) {
                return $key === 'test_key'
                    && is_array($value)
                    && $value['body'] === 'cached body'
                    && $value['expires_at'] > time()
                    && is_int($ttl);
            })
        ;

        $result = callPrivateMethod($handler, 'handleNotModified', [
            $response304,
            $cachedItem,
            $cacheMock,
            'test_key',
            $cacheConfig,
        ]);

        expect($result)->toBeInstanceOf(Response::class);
        expect($result->status())->toBe(200);
        expect((string)$result->getBody())->toBe('cached body');
    });
});

describe('CacheHandler: Constructor', function () {
    it('accepts custom dependencies', function () {
        $requestExecutor = new RequestExecutorHandler();
        $retryHandler = new RetryHandler();

        $handler = new CacheHandler($requestExecutor, $retryHandler);

        $actualRequestExecutor = getPrivateProperty($handler, 'requestExecutor');
        $actualRetryHandler = getPrivateProperty($handler, 'retryHandler');

        expect($actualRequestExecutor)->toBe($requestExecutor);
        expect($actualRetryHandler)->toBe($retryHandler);
    });

    it('creates default dependencies when not provided', function () {
        $handler = new CacheHandler();

        $requestExecutor = getPrivateProperty($handler, 'requestExecutor');
        $retryHandler = getPrivateProperty($handler, 'retryHandler');

        expect($requestExecutor)->toBeInstanceOf(RequestExecutorHandler::class);
        expect($retryHandler)->toBeInstanceOf(RetryHandler::class);
    });
});

afterEach(function () {
    Mockery::close();
});
