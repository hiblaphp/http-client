<?php

use Hibla\HttpClient\Testing\MockedRequest;

describe('MockedRequest', function () {
    test('can be instantiated with default method', function () {
        $request = new MockedRequest();

        expect($request->getMethod())->toBe('*');
    });

    test('can be instantiated with specific method', function () {
        $request = new MockedRequest('POST');

        expect($request->getMethod())->toBe('POST');
    });

    test('can set and get URL pattern', function () {
        $request = new MockedRequest();
        $request->setUrlPattern('https://api.example.com/*');

        expect($request->getUrlPattern())->toBe('https://api.example.com/*');
    });

    test('can add header matchers', function () {
        $request = new MockedRequest();
        $request->addHeaderMatcher('Content-Type', 'application/json');
        $request->addHeaderMatcher('Authorization', 'Bearer token123');

        $array = $request->toArray();
        expect($array['headerMatchers'])->toBe([
            'content-type' => 'application/json',
            'authorization' => 'Bearer token123',
        ]);
    });

    test('can set body matcher', function () {
        $request = new MockedRequest();
        $request->setBodyMatcher('*test*');

        expect($request->toArray()['bodyMatcher'])->toBe('*test*');
    });

    test('can set JSON matcher', function () {
        $request = new MockedRequest();
        $jsonData = ['key' => 'value', 'number' => 42];
        $request->setJsonMatcher($jsonData);

        expect($request->toArray()['jsonMatcher'])->toBe($jsonData);
    });

    test('can set status code', function () {
        $request = new MockedRequest();
        $request->setStatusCode(404);

        expect($request->getStatusCode())->toBe(404);
    });

    test('can set response body', function () {
        $request = new MockedRequest();
        $request->setBody('Response content');

        expect($request->getBody())->toBe('Response content')
            ->and($request->getBodySequence())->toBe([])
        ;
    });

    test('can set body sequence', function () {
        $request = new MockedRequest();
        $chunks = ['chunk1', 'chunk2', 'chunk3'];
        $request->setBodySequence($chunks);

        expect($request->getBodySequence())->toBe($chunks)
            ->and($request->getBody())->toBe('chunk1chunk2chunk3')
        ;
    });

    test('can add response headers', function () {
        $request = new MockedRequest();
        $request->addResponseHeader('Content-Type', 'application/json');
        $request->addResponseHeader('X-Custom', 'value1');

        $headers = $request->getHeaders();
        expect($headers)->toBe([
            'Content-Type' => 'application/json',
            'X-Custom' => 'value1',
        ]);
    });

    test('can add multiple values for same header', function () {
        $request = new MockedRequest();
        $request->addResponseHeader('Set-Cookie', 'cookie1=value1');
        $request->addResponseHeader('Set-Cookie', 'cookie2=value2');

        $headers = $request->getHeaders();
        expect($headers['Set-Cookie'])->toBe(['cookie1=value1', 'cookie2=value2']);
    });

    test('can set fixed delay', function () {
        $request = new MockedRequest();
        $request->setDelay(2.5);

        expect($request->getDelay())->toBe(2.5);
    });

    test('can set error message', function () {
        $request = new MockedRequest();
        $request->setError('Connection failed');

        expect($request->getError())->toBe('Connection failed')
            ->and($request->shouldFail())->toBeTrue()
        ;
    });

    test('can set timeout', function () {
        $request = new MockedRequest();
        $request->setTimeout(5.0);

        expect($request->getTimeoutDuration())->toBe(5.0)
            ->and($request->isTimeout())->toBeTrue()
            ->and($request->shouldFail())->toBeTrue()
            ->and($request->getError())->toContain('timed out')
        ;
    });

    test('can set retryable flag', function () {
        $request = new MockedRequest();
        $request->setRetryable(true);

        expect($request->isRetryableFailure())->toBeTrue();
    });

    test('can set persistent flag', function () {
        $request = new MockedRequest();
        $request->setPersistent(true);

        expect($request->isPersistent())->toBeTrue();
    });

    test('can set random delay range', function () {
        $request = new MockedRequest();
        $request->setRandomDelayRange(1.0, 3.0);

        expect($request->getRandomDelayRange())->toBe([1.0, 3.0]);
    });

    test('generates random delay within range', function () {
        $request = new MockedRequest();
        $request->setRandomDelayRange(1.0, 3.0);

        $delay = $request->generateRandomDelay();

        expect($delay)->toBeGreaterThanOrEqual(1.0)
            ->and($delay)->toBeLessThanOrEqual(3.0)
        ;
    });

    test('can mark as SSE response', function () {
        $request = new MockedRequest();
        $request->asSSE();

        expect($request->isSSE())->toBeTrue();
    });

    test('can set SSE events', function () {
        $request = new MockedRequest();
        $events = [
            ['id' => '1', 'event' => 'message', 'data' => 'Hello'],
            ['data' => 'World'],
        ];
        $request->setSSEEvents($events);

        expect($request->getSSEEvents())->toBe($events);
    });

    test('can add single SSE event', function () {
        $request = new MockedRequest();
        $request->addSSEEvent(['id' => '1', 'data' => 'Test']);
        $request->addSSEEvent(['data' => 'Another']);

        expect($request->getSSEEvents())->toHaveCount(2);
    });

    test('can set and get SSE stream config', function () {
        $request = new MockedRequest();
        $config = ['key' => 'value'];
        $request->setSSEStreamConfig($config);

        expect($request->getSSEStreamConfig())->toBe($config)
            ->and($request->hasStreamConfig())->toBeTrue()
        ;
    });

    describe('matches', function () {
        test('matches any method with wildcard', function () {
            $request = new MockedRequest('*');
            $request->setUrlPattern('https://api.example.com/*');

            expect($request->matches('GET', 'https://api.example.com/users', []))->toBeTrue()
                ->and($request->matches('POST', 'https://api.example.com/users', []))->toBeTrue()
            ;
        });

        test('matches specific method', function () {
            $request = new MockedRequest('POST');
            $request->setUrlPattern('https://api.example.com/*');

            expect($request->matches('POST', 'https://api.example.com/users', []))->toBeTrue()
                ->and($request->matches('GET', 'https://api.example.com/users', []))->toBeFalse()
            ;
        });

        test('matches URL pattern', function () {
            $request = new MockedRequest();
            $request->setUrlPattern('https://api.example.com/users/*');

            expect($request->matches('GET', 'https://api.example.com/users/123', []))->toBeTrue()
                ->and($request->matches('GET', 'https://api.example.com/posts/123', []))->toBeFalse()
            ;
        });

        test('matches with trailing slash leniency', function () {
            $request = new MockedRequest();
            $request->setUrlPattern('https://api.example.com/users');

            expect($request->matches('GET', 'https://api.example.com/users/', []))->toBeTrue()
                ->and($request->matches('GET', 'https://api.example.com/users', []))->toBeTrue()
            ;
        });

        test('matches required headers', function () {
            $request = new MockedRequest();
            $request->setUrlPattern('https://api.example.com/*');
            $request->addHeaderMatcher('Content-Type', 'application/json');

            $options = [
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer token',
                ],
            ];

            expect($request->matches('POST', 'https://api.example.com/users', $options))->toBeTrue();
        });

        test('does not match when required header is missing', function () {
            $request = new MockedRequest();
            $request->setUrlPattern('https://api.example.com/*');
            $request->addHeaderMatcher('Authorization', 'Bearer token');

            $options = [
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                ],
            ];

            expect($request->matches('POST', 'https://api.example.com/users', $options))->toBeFalse();
        });

        test('matches body pattern', function () {
            $request = new MockedRequest();
            $request->setUrlPattern('https://api.example.com/*');
            $request->setBodyMatcher('*test*');

            $options = [
                CURLOPT_POSTFIELDS => 'this is a test body',
            ];

            expect($request->matches('POST', 'https://api.example.com/users', $options))->toBeTrue();
        });

        test('does not match when body pattern does not match', function () {
            $request = new MockedRequest();
            $request->setUrlPattern('https://api.example.com/*');
            $request->setBodyMatcher('*test*');

            $options = [
                CURLOPT_POSTFIELDS => 'different body',
            ];

            expect($request->matches('POST', 'https://api.example.com/users', $options))->toBeFalse();
        });

        test('matches JSON body', function () {
            $request = new MockedRequest();
            $request->setUrlPattern('https://api.example.com/*');
            $request->setJsonMatcher(['name' => 'John', 'age' => 30]);

            $options = [
                CURLOPT_POSTFIELDS => json_encode(['name' => 'John', 'age' => 30]),
            ];

            expect($request->matches('POST', 'https://api.example.com/users', $options))->toBeTrue();
        });

        test('does not match when JSON body is different', function () {
            $request = new MockedRequest();
            $request->setUrlPattern('https://api.example.com/*');
            $request->setJsonMatcher(['name' => 'John', 'age' => 30]);

            $options = [
                CURLOPT_POSTFIELDS => json_encode(['name' => 'Jane', 'age' => 25]),
            ];

            expect($request->matches('POST', 'https://api.example.com/users', $options))->toBeFalse();
        });
    });

    describe('serialization', function () {
        test('can convert to array', function () {
            $request = new MockedRequest('POST');
            $request->setUrlPattern('https://api.example.com/*');
            $request->setStatusCode(201);
            $request->setBody('Success');
            $request->setDelay(1.5);
            $request->setPersistent(true);

            $array = $request->toArray();

            expect($array)->toHaveKeys([
                'method', 'urlPattern', 'statusCode', 'body',
                'delay', 'persistent',
            ])
            ->and($array['method'])->toBe('POST')
            ->and($array['urlPattern'])->toBe('https://api.example.com/*')
            ->and($array['statusCode'])->toBe(201)
            ->and($array['body'])->toBe('Success')
            ->and($array['delay'])->toBe(1.5)
            ->and($array['persistent'])->toBeTrue()
            ;
        });

        test('can create from array', function () {
            $data = [
                'method' => 'PUT',
                'urlPattern' => 'https://api.example.com/users/*',
                'statusCode' => 200,
                'body' => 'Updated',
                'delay' => 2.0,
                'persistent' => true,
                'headerMatchers' => ['authorization' => 'Bearer token'],
                'isSSE' => true,
                'sseEvents' => [['data' => 'Test']],
            ];

            $request = MockedRequest::fromArray($data);

            expect($request->getMethod())->toBe('PUT')
                ->and($request->getUrlPattern())->toBe('https://api.example.com/users/*')
                ->and($request->getStatusCode())->toBe(200)
                ->and($request->getBody())->toBe('Updated')
                ->and($request->getDelay())->toBe(2.0)
                ->and($request->isPersistent())->toBeTrue()
                ->and($request->isSSE())->toBeTrue()
                ->and($request->getSSEEvents())->toHaveCount(1)
            ;
        });

        test('fromArray handles invalid data gracefully', function () {
            $data = [
                'method' => 123, // Invalid type
                'statusCode' => 'invalid', // Invalid type
                'delay' => 'not a number', // Invalid type
            ];

            $request = MockedRequest::fromArray($data);

            expect($request->getMethod())->toBe('*')
                ->and($request->getStatusCode())->toBe(200)
                ->and($request->getDelay())->toBe(0.0)
            ;
        });

        test('round trip serialization preserves data', function () {
            $original = new MockedRequest('DELETE');
            $original->setUrlPattern('https://api.example.com/users/*');
            $original->setStatusCode(204);
            $original->setBody('Deleted');
            $original->addResponseHeader('X-Custom', 'value');
            $original->setDelay(1.0);
            $original->setRandomDelayRange(0.5, 2.0);
            $original->setPersistent(true);
            $original->asSSE();

            $array = $original->toArray();
            $restored = MockedRequest::fromArray($array);

            expect($restored->toArray())->toBe($array);
        });
    });

    test('timeout takes precedence in getDelay', function () {
        $request = new MockedRequest();
        $request->setDelay(1.0);
        $request->setTimeout(3.0);

        expect($request->getDelay())->toBe(3.0);
    });

    test('random delay takes precedence over fixed delay in getDelay', function () {
        $request = new MockedRequest();
        $request->setDelay(1.0);
        $request->setRandomDelayRange(2.0, 4.0);

        $delay = $request->getDelay();

        expect($delay)->toBeGreaterThanOrEqual(2.0)
            ->and($delay)->toBeLessThanOrEqual(4.0);
    });
});
