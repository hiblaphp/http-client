<?php

use Hibla\HttpClient\Testing\Utilities\RecordedRequest;

describe('RecordedRequest', function () {

    describe('Construction and Basic Properties', function () {
        it('constructs with method, url, and options', function () {
            $request = new RecordedRequest('GET', 'https://example.com', []);

            expect($request->method)->toBe('GET')
                ->and($request->url)->toBe('https://example.com')
                ->and($request->options)->toBe([])
            ;
        });

        it('converts method to uppercase', function () {
            $request = new RecordedRequest('post', 'https://example.com', []);

            expect($request->method)->toBe('POST')
                ->and($request->getMethod())->toBe('POST')
            ;
        });

        it('exposes public properties', function () {
            $options = ['timeout' => 30];
            $request = new RecordedRequest('PUT', 'https://api.example.com', $options);

            expect($request->method)->toBe('PUT')
                ->and($request->url)->toBe('https://api.example.com')
                ->and($request->options)->toBe($options)
            ;
        });
    });

    describe('Header Parsing - cURL Format', function () {
        it('parses cURL-style headers from CURLOPT_HTTPHEADER', function () {
            $request = new RecordedRequest('GET', 'https://example.com', [
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
            ]);

            expect($request->hasHeader('Content-Type'))->toBeTrue()
                ->and($request->hasHeader('Accept'))->toBeTrue()
                ->and($request->getHeader('content-type'))->toBe('application/json')
                ->and($request->getHeader('accept'))->toBe('application/json')
            ;
        });

        it('handles header names with spaces', function () {
            $request = new RecordedRequest('GET', 'https://example.com', [
                CURLOPT_HTTPHEADER => [
                    'Content-Type : application/json',
                    ' Accept : text/html ',
                ],
            ]);

            expect($request->getHeader('content-type'))->toBe('application/json')
                ->and($request->getHeader('accept'))->toBe('text/html')
            ;
        });

        it('handles multiple values for the same header', function () {
            $request = new RecordedRequest('GET', 'https://example.com', [
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Accept: text/html',
                    'Accept: application/xml',
                ],
            ]);

            $acceptHeader = $request->getHeader('accept');

            expect($acceptHeader)->toBeArray()
                ->and($acceptHeader)->toHaveCount(3)
                ->and($acceptHeader)->toContain('application/json')
                ->and($acceptHeader)->toContain('text/html')
                ->and($acceptHeader)->toContain('application/xml')
            ;
        });

        it('ignores invalid header entries', function () {
            $request = new RecordedRequest('GET', 'https://example.com', [
                CURLOPT_HTTPHEADER => [
                    'Valid-Header: value',
                    'InvalidHeaderNoColon',
                    123, // Non-string
                    null,
                ],
            ]);

            expect($request->hasHeader('Valid-Header'))->toBeTrue()
                ->and($request->hasHeader('InvalidHeaderNoColon'))->toBeFalse()
                ->and($request->getHeaders())->toHaveCount(1)
            ;
        });

        it('handles headers with colons in the value', function () {
            $request = new RecordedRequest('GET', 'https://example.com', [
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer token:with:colons',
                    'X-Custom: key:value:pair',
                ],
            ]);

            expect($request->getHeader('authorization'))->toBe('Bearer token:with:colons')
                ->and($request->getHeader('x-custom'))->toBe('key:value:pair')
            ;
        });
    });

    describe('Header Parsing - Fetch Format', function () {
        it('parses fetch-style headers from headers array', function () {
            $request = new RecordedRequest('GET', 'https://example.com', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer token123',
                ],
            ]);

            expect($request->hasHeader('Content-Type'))->toBeTrue()
                ->and($request->hasHeader('Authorization'))->toBeTrue()
                ->and($request->getHeader('content-type'))->toBe('application/json')
                ->and($request->getHeader('authorization'))->toBe('Bearer token123')
            ;
        });

        it('normalizes fetch-style header names to lowercase', function () {
            $request = new RecordedRequest('GET', 'https://example.com', [
                'headers' => [
                    'CONTENT-TYPE' => 'text/html',
                    'X-Custom-Header' => 'custom-value',
                ],
            ]);

            expect($request->getHeader('content-type'))->toBe('text/html')
                ->and($request->getHeader('x-custom-header'))->toBe('custom-value')
            ;
        });

        it('handles headers with spaces in fetch format', function () {
            $request = new RecordedRequest('GET', 'https://example.com', [
                'headers' => [
                    ' Content-Type ' => ' application/json ',
                    ' Accept ' => ' text/html ',
                ],
            ]);

            expect($request->getHeader('content-type'))->toBe('application/json')
                ->and($request->getHeader('accept'))->toBe('text/html')
            ;
        });

        it('ignores non-scalar header values in fetch format', function () {
            $request = new RecordedRequest('GET', 'https://example.com', [
                'headers' => [
                    'Valid-Header' => 'value',
                    'Array-Header' => ['should', 'be', 'ignored'],
                    'Object-Header' => (object)['key' => 'value'],
                ],
            ]);

            expect($request->hasHeader('Valid-Header'))->toBeTrue()
                ->and($request->hasHeader('Array-Header'))->toBeFalse()
                ->and($request->hasHeader('Object-Header'))->toBeFalse()
                ->and($request->getHeaders())->toHaveCount(1)
            ;
        });
    });

    describe('Header Parsing - Mixed Formats', function () {
        it('parses headers from both cURL and fetch formats', function () {
            $request = new RecordedRequest('GET', 'https://example.com', [
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                ],
                'headers' => [
                    'Authorization' => 'Bearer token123',
                ],
            ]);

            expect($request->hasHeader('Content-Type'))->toBeTrue()
                ->and($request->hasHeader('Authorization'))->toBeTrue()
                ->and($request->getHeader('content-type'))->toBe('application/json')
                ->and($request->getHeader('authorization'))->toBe('Bearer token123')
            ;
        });

        it('fetch format headers override cURL format for same header', function () {
            $request = new RecordedRequest('GET', 'https://example.com', [
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/xml',
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            expect($request->getHeader('content-type'))->toBe('application/json');
        });
    });

    describe('Header Retrieval Methods', function () {
        it('hasHeader is case-insensitive', function () {
            $request = new RecordedRequest('GET', 'https://example.com', [
                'headers' => ['Content-Type' => 'application/json'],
            ]);

            expect($request->hasHeader('content-type'))->toBeTrue()
                ->and($request->hasHeader('Content-Type'))->toBeTrue()
                ->and($request->hasHeader('CONTENT-TYPE'))->toBeTrue()
                ->and($request->hasHeader('CoNtEnT-tYpE'))->toBeTrue()
            ;
        });

        it('getHeader returns null for non-existent headers', function () {
            $request = new RecordedRequest('GET', 'https://example.com', []);

            expect($request->getHeader('Non-Existent'))->toBeNull()
                ->and($request->hasHeader('Non-Existent'))->toBeFalse()
            ;
        });

        it('getHeaderLine joins array values with comma', function () {
            $request = new RecordedRequest('GET', 'https://example.com', [
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Accept: text/html',
                    'Accept: application/xml',
                ],
            ]);

            expect($request->getHeaderLine('accept'))->toBe('application/json, text/html, application/xml');
        });

        it('getHeaderLine returns string value as-is', function () {
            $request = new RecordedRequest('GET', 'https://example.com', [
                'headers' => ['Content-Type' => 'application/json'],
            ]);

            expect($request->getHeaderLine('content-type'))->toBe('application/json');
        });

        it('getHeaderLine returns null for non-existent header', function () {
            $request = new RecordedRequest('GET', 'https://example.com', []);

            expect($request->getHeaderLine('Non-Existent'))->toBeNull();
        });

        it('getHeaders returns all parsed headers', function () {
            $request = new RecordedRequest('GET', 'https://example.com', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer token',
                    'Accept' => 'application/json',
                ],
            ]);

            $headers = $request->getHeaders();

            expect($headers)->toBeArray()
                ->and($headers)->toHaveCount(3)
                ->and($headers)->toHaveKey('content-type')
                ->and($headers)->toHaveKey('authorization')
                ->and($headers)->toHaveKey('accept')
            ;
        });
    });

    describe('Body Parsing - Mixed Formats', function () {
        it('prefers CURLOPT_POSTFIELDS over body option', function () {
            $request = new RecordedRequest('POST', 'https://example.com', [
                CURLOPT_POSTFIELDS => 'curl body',
                'body' => 'fetch body',
            ]);

            expect($request->getBody())->toBe('curl body');
        });

        it('uses body option when CURLOPT_POSTFIELDS is not a string', function () {
            $request = new RecordedRequest('POST', 'https://example.com', [
                CURLOPT_POSTFIELDS => ['array' => 'data'],
                'body' => 'fetch body',
            ]);

            expect($request->getBody())->toBe('fetch body');
        });

        it('uses body option when CURLOPT_POSTFIELDS is missing', function () {
            $request = new RecordedRequest('POST', 'https://example.com', [
                'body' => 'fetch body',
            ]);

            expect($request->getBody())->toBe('fetch body');
        });
    });

    describe('Body Parsing - Fetch Format', function () {
        it('parses body from body option as string', function () {
            $body = 'request body';
            $request = new RecordedRequest('POST', 'https://example.com', [
                'body' => $body,
            ]);

            expect($request->getBody())->toBe($body);
        });

        it('parses JSON body from body option', function () {
            $data = ['email' => 'test@example.com', 'active' => true];
            $request = new RecordedRequest('POST', 'https://example.com', [
                'body' => json_encode($data),
            ]);

            expect($request->getBody())->toBe(json_encode($data))
                ->and($request->isJson())->toBeTrue()
                ->and($request->getJson())->toBe($data)
            ;
        });

        it('ignores non-string body option', function () {
            $request = new RecordedRequest('POST', 'https://example.com', [
                'body' => ['key' => 'value'],
            ]);

            expect($request->getBody())->toBeNull();
        });
    });

    describe('JSON Detection and Parsing', function () {
        it('detects valid JSON body', function () {
            $request = new RecordedRequest('POST', 'https://example.com', [
                'body' => json_encode(['key' => 'value']),
            ]);

            expect($request->isJson())->toBeTrue();
        });

        it('does not detect invalid JSON as JSON', function () {
            $request = new RecordedRequest('POST', 'https://example.com', [
                'body' => 'not json',
            ]);

            expect($request->isJson())->toBeFalse()
                ->and($request->getJson())->toBeNull()
            ;
        });

        it('does not detect non-array JSON as valid', function () {
            $request = new RecordedRequest('POST', 'https://example.com', [
                'body' => json_encode('just a string'),
            ]);

            expect($request->isJson())->toBeFalse()
                ->and($request->getJson())->toBeNull()
            ;
        });

        it('parses complex nested JSON', function () {
            $data = [
                'user' => [
                    'name' => 'John',
                    'address' => [
                        'street' => '123 Main St',
                        'city' => 'New York',
                    ],
                ],
                'items' => [1, 2, 3],
            ];

            $request = new RecordedRequest('POST', 'https://example.com', [
                'body' => json_encode($data),
            ]);

            expect($request->isJson())->toBeTrue()
                ->and($request->getJson())->toBe($data)
                ->and($request->getJson()['user']['name'])->toBe('John')
                ->and($request->getJson()['items'])->toBe([1, 2, 3])
            ;
        });

        it('parses empty JSON object', function () {
            $request = new RecordedRequest('POST', 'https://example.com', [
                'body' => '{}',
            ]);

            expect($request->isJson())->toBeTrue()
                ->and($request->getJson())->toBe([])
            ;
        });

        it('parses empty JSON array', function () {
            $request = new RecordedRequest('POST', 'https://example.com', [
                'body' => '[]',
            ]);

            expect($request->isJson())->toBeTrue()
                ->and($request->getJson())->toBe([])
            ;
        });
    });

    describe('Getter Methods', function () {
        it('getMethod returns uppercase method', function () {
            $request = new RecordedRequest('get', 'https://example.com', []);

            expect($request->getMethod())->toBe('GET');
        });

        it('getUrl returns the URL', function () {
            $url = 'https://api.example.com/users/123';
            $request = new RecordedRequest('GET', $url, []);

            expect($request->getUrl())->toBe($url);
        });

        it('getOptions returns raw options', function () {
            $options = [
                CURLOPT_TIMEOUT => 30,
                'custom_option' => 'value',
            ];

            $request = new RecordedRequest('GET', 'https://example.com', $options);

            expect($request->getOptions())->toBe($options);
        });

        it('getBody returns null when no body is set', function () {
            $request = new RecordedRequest('GET', 'https://example.com', []);

            expect($request->getBody())->toBeNull();
        });

        it('getJson returns null when no JSON body', function () {
            $request = new RecordedRequest('POST', 'https://example.com', [
                'body' => 'plain text',
            ]);

            expect($request->getJson())->toBeNull();
        });
    });

    describe('toArray Method', function () {
        it('converts to array with all properties', function () {
            $data = ['name' => 'Test', 'value' => 123];
            $request = new RecordedRequest('POST', 'https://example.com', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer token',
                ],
                'body' => json_encode($data),
            ]);

            $array = $request->toArray();

            expect($array)->toBeArray()
                ->and($array)->toHaveKey('method')
                ->and($array)->toHaveKey('url')
                ->and($array)->toHaveKey('headers')
                ->and($array)->toHaveKey('body')
                ->and($array)->toHaveKey('json')
                ->and($array['method'])->toBe('POST')
                ->and($array['url'])->toBe('https://example.com')
                ->and($array['headers'])->toHaveCount(2)
                ->and($array['body'])->toBe(json_encode($data))
                ->and($array['json'])->toBe($data)
            ;
        });

        it('includes null values in array', function () {
            $request = new RecordedRequest('GET', 'https://example.com', []);

            $array = $request->toArray();

            expect($array['body'])->toBeNull()
                ->and($array['json'])->toBeNull()
                ->and($array['headers'])->toBe([])
            ;
        });

        it('represents request with multiple header values correctly', function () {
            $request = new RecordedRequest('GET', 'https://example.com', [
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Accept: text/html',
                ],
            ]);

            $array = $request->toArray();

            expect($array['headers']['accept'])->toBeArray()
                ->and($array['headers']['accept'])->toHaveCount(2)
            ;
        });
    });

    describe('Edge Cases', function () {
        it('handles empty options array', function () {
            $request = new RecordedRequest('GET', 'https://example.com', []);

            expect($request->getHeaders())->toBe([])
                ->and($request->getBody())->toBeNull()
                ->and($request->getJson())->toBeNull()
                ->and($request->isJson())->toBeFalse()
            ;
        });

        it('handles URL with query parameters', function () {
            $url = 'https://example.com/api?param1=value1&param2=value2';
            $request = new RecordedRequest('GET', $url, []);

            expect($request->getUrl())->toBe($url);
        });

        it('handles URL with fragments', function () {
            $url = 'https://example.com/page#section';
            $request = new RecordedRequest('GET', $url, []);

            expect($request->getUrl())->toBe($url);
        });

        it('handles empty body string', function () {
            $request = new RecordedRequest('POST', 'https://example.com', [
                'body' => '',
            ]);

            expect($request->getBody())->toBe('')
                ->and($request->isJson())->toBeFalse()
            ;
        });

        it('handles whitespace-only body', function () {
            $request = new RecordedRequest('POST', 'https://example.com', [
                'body' => '   ',
            ]);

            expect($request->getBody())->toBe('   ')
                ->and($request->isJson())->toBeFalse()
            ;
        });

        it('handles special characters in headers', function () {
            $request = new RecordedRequest('GET', 'https://example.com', [
                'headers' => [
                    'X-Custom-Header' => 'value with spaces and special!@#$%',
                ],
            ]);

            expect($request->getHeader('x-custom-header'))->toBe('value with spaces and special!@#$%');
        });

        it('handles unicode in body', function () {
            $data = ['message' => 'Hello 世界 🌍'];
            $request = new RecordedRequest('POST', 'https://example.com', [
                'body' => json_encode($data),
            ]);

            expect($request->isJson())->toBeTrue()
                ->and($request->getJson())->toBe($data)
            ;
        });

        it('handles numeric string in body', function () {
            $request = new RecordedRequest('POST', 'https://example.com', [
                'body' => '12345',
            ]);

            expect($request->getBody())->toBe('12345')
                ->and($request->isJson())->toBeFalse()
            ;
        });

        it('handles boolean values in JSON', function () {
            $data = ['active' => true, 'deleted' => false];
            $request = new RecordedRequest('POST', 'https://example.com', [
                'body' => json_encode($data),
            ]);

            expect($request->getJson())->toBe($data)
                ->and($request->getJson()['active'])->toBeTrue()
                ->and($request->getJson()['deleted'])->toBeFalse()
            ;
        });

        it('handles null values in JSON', function () {
            $data = ['value' => null];
            $request = new RecordedRequest('POST', 'https://example.com', [
                'body' => json_encode($data),
            ]);

            expect($request->getJson())->toBe($data)
                ->and($request->getJson()['value'])->toBeNull()
            ;
        });
    });

    describe('Real-World Scenarios', function () {
        it('handles typical GET request with headers', function () {
            $request = new RecordedRequest('GET', 'https://api.example.com/users', [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer abc123',
                    'User-Agent' => 'MyApp/1.0',
                ],
            ]);

            expect($request->getMethod())->toBe('GET')
                ->and($request->hasHeader('Accept'))->toBeTrue()
                ->and($request->hasHeader('Authorization'))->toBeTrue()
                ->and($request->getBody())->toBeNull()
            ;
        });

        it('handles typical POST request with JSON body', function () {
            $payload = [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'age' => 30,
            ];

            $request = new RecordedRequest('POST', 'https://api.example.com/users', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => json_encode($payload),
            ]);

            expect($request->getMethod())->toBe('POST')
                ->and($request->isJson())->toBeTrue()
                ->and($request->getJson())->toBe($payload)
                ->and($request->getHeader('content-type'))->toBe('application/json')
            ;
        });

        it('handles PATCH request with partial update', function () {
            $request = new RecordedRequest('PATCH', 'https://api.example.com/users/123', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode(['name' => 'Updated Name']),
            ]);

            expect($request->getMethod())->toBe('PATCH')
                ->and($request->getJson())->toHaveKey('name')
                ->and($request->getJson()['name'])->toBe('Updated Name')
            ;
        });

        it('handles DELETE request', function () {
            $request = new RecordedRequest('DELETE', 'https://api.example.com/users/123', [
                'headers' => [
                    'Authorization' => 'Bearer token',
                ],
            ]);

            expect($request->getMethod())->toBe('DELETE')
                ->and($request->hasHeader('Authorization'))->toBeTrue()
                ->and($request->getBody())->toBeNull()
            ;
        });

        it('handles form-encoded body', function () {
            $formData = 'name=John+Doe&email=john%40example.com&age=30';
            $request = new RecordedRequest('POST', 'https://example.com/form', [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => $formData,
            ]);

            expect($request->getBody())->toBe($formData)
                ->and($request->isJson())->toBeFalse()
                ->and($request->getHeader('content-type'))->toBe('application/x-www-form-urlencoded')
            ;
        });
    });
});
