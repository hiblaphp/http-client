<?php

use Hibla\HttpClient\Testing\Utilities\RecordedRequest;
use Hibla\HttpClient\Testing\Utilities\RequestRecorder;

describe('RequestRecorder', function () {

    describe('Recording Requests', function () {
        it('records a request with method, URL, and options', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/users', []);

            $history = $recorder->getRequestHistory();

            expect($history)->toHaveCount(1)
                ->and($history[0])->toBeInstanceOf(RecordedRequest::class)
                ->and($history[0]->method)->toBe('GET')
                ->and($history[0]->url)->toBe('https://api.example.com/users')
            ;
        });

        it('records multiple requests in order', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/users', []);
            $recorder->recordRequest('POST', 'https://api.example.com/posts', []);
            $recorder->recordRequest('PUT', 'https://api.example.com/comments', []);

            $history = $recorder->getRequestHistory();

            expect($history)->toHaveCount(3)
                ->and($history[0]->method)->toBe('GET')
                ->and($history[0]->url)->toBe('https://api.example.com/users')
                ->and($history[1]->method)->toBe('POST')
                ->and($history[1]->url)->toBe('https://api.example.com/posts')
                ->and($history[2]->method)->toBe('PUT')
                ->and($history[2]->url)->toBe('https://api.example.com/comments')
            ;
        });

        it('records request options', function () {
            $recorder = new RequestRecorder();
            $options = [
                'headers' => ['Authorization' => 'Bearer token'],
                'json' => ['name' => 'John Doe'],
            ];

            $recorder->recordRequest('POST', 'https://api.example.com/users', $options);

            $history = $recorder->getRequestHistory();

            expect($history[0]->options)->toBe($options)
                ->and($history[0]->options['headers'])->toBe(['Authorization' => 'Bearer token'])
                ->and($history[0]->options['json'])->toBe(['name' => 'John Doe'])
            ;
        });

        it('records requests with empty options', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/users', []);

            $history = $recorder->getRequestHistory();

            expect($history[0]->options)->toBe([]);
        });

        it('records different HTTP methods', function () {
            $recorder = new RequestRecorder();
            $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

            foreach ($methods as $method) {
                $recorder->recordRequest($method, 'https://api.example.com/resource', []);
            }

            $history = $recorder->getRequestHistory();

            expect($history)->toHaveCount(7);
            foreach ($methods as $index => $method) {
                expect($history[$index]->method)->toBe($method);
            }
        });

        it('records requests with complex options', function () {
            $recorder = new RequestRecorder();
            $options = [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer token123',
                ],
                'json' => [
                    'user' => [
                        'name' => 'John',
                        'email' => 'john@example.com',
                    ],
                ],
                'timeout' => 30,
                'verify' => false,
            ];

            $recorder->recordRequest('POST', 'https://api.example.com/users', $options);

            $history = $recorder->getRequestHistory();

            expect($history[0]->options)->toBe($options);
        });
    });

    describe('Request History', function () {
        it('returns empty array when no requests recorded', function () {
            $recorder = new RequestRecorder();

            $history = $recorder->getRequestHistory();

            expect($history)->toBeArray()
                ->and($history)->toBeEmpty()
            ;
        });

        it('maintains request history across multiple recordings', function () {
            $recorder = new RequestRecorder();

            $recorder->recordRequest('GET', 'https://api.example.com/1', []);
            expect($recorder->getRequestHistory())->toHaveCount(1);

            $recorder->recordRequest('POST', 'https://api.example.com/2', []);
            expect($recorder->getRequestHistory())->toHaveCount(2);

            $recorder->recordRequest('PUT', 'https://api.example.com/3', []);
            expect($recorder->getRequestHistory())->toHaveCount(3);
        });

        it('returns all recorded requests', function () {
            $recorder = new RequestRecorder();

            for ($i = 1; $i <= 10; $i++) {
                $recorder->recordRequest('GET', "https://api.example.com/resource/{$i}", []);
            }

            $history = $recorder->getRequestHistory();

            expect($history)->toHaveCount(10);
            foreach ($history as $index => $request) {
                expect($request->url)->toBe('https://api.example.com/resource/' . ($index + 1));
            }
        });
    });

    describe('Recording Control', function () {
        it('starts with recording enabled by default', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/users', []);

            $history = $recorder->getRequestHistory();

            expect($history)->toHaveCount(1);
        });

        it('does not record when recording is disabled', function () {
            $recorder = new RequestRecorder();
            $recorder->setRecordRequests(false);
            $recorder->recordRequest('GET', 'https://api.example.com/users', []);

            $history = $recorder->getRequestHistory();

            expect($history)->toBeEmpty();
        });

        it('can enable recording after disabling', function () {
            $recorder = new RequestRecorder();
            $recorder->setRecordRequests(false);
            $recorder->recordRequest('GET', 'https://api.example.com/ignored', []);

            $recorder->setRecordRequests(true);
            $recorder->recordRequest('POST', 'https://api.example.com/recorded', []);

            $history = $recorder->getRequestHistory();

            expect($history)->toHaveCount(1)
                ->and($history[0]->url)->toBe('https://api.example.com/recorded')
            ;
        });

        it('can disable recording after enabling', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/recorded', []);

            $recorder->setRecordRequests(false);
            $recorder->recordRequest('POST', 'https://api.example.com/ignored', []);

            $history = $recorder->getRequestHistory();

            expect($history)->toHaveCount(1)
                ->and($history[0]->url)->toBe('https://api.example.com/recorded')
            ;
        });

        it('toggles recording multiple times', function () {
            $recorder = new RequestRecorder();

            $recorder->recordRequest('GET', 'https://api.example.com/1', []); // recorded
            $recorder->setRecordRequests(false);
            $recorder->recordRequest('GET', 'https://api.example.com/2', []); // ignored
            $recorder->setRecordRequests(true);
            $recorder->recordRequest('GET', 'https://api.example.com/3', []); // recorded
            $recorder->setRecordRequests(false);
            $recorder->recordRequest('GET', 'https://api.example.com/4', []); // ignored

            $history = $recorder->getRequestHistory();

            expect($history)->toHaveCount(2)
                ->and($history[0]->url)->toBe('https://api.example.com/1')
                ->and($history[1]->url)->toBe('https://api.example.com/3')
            ;
        });
    });

    describe('Reset', function () {
        it('clears all recorded requests', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/1', []);
            $recorder->recordRequest('POST', 'https://api.example.com/2', []);
            $recorder->recordRequest('PUT', 'https://api.example.com/3', []);

            $recorder->reset();

            $history = $recorder->getRequestHistory();

            expect($history)->toBeEmpty();
        });

        it('allows recording after reset', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/old', []);
            $recorder->reset();
            $recorder->recordRequest('POST', 'https://api.example.com/new', []);

            $history = $recorder->getRequestHistory();

            expect($history)->toHaveCount(1)
                ->and($history[0]->url)->toBe('https://api.example.com/new')
            ;
        });

        it('can be called multiple times', function () {
            $recorder = new RequestRecorder();

            $recorder->recordRequest('GET', 'https://api.example.com/1', []);
            $recorder->reset();
            expect($recorder->getRequestHistory())->toBeEmpty();

            $recorder->recordRequest('POST', 'https://api.example.com/2', []);
            $recorder->reset();
            expect($recorder->getRequestHistory())->toBeEmpty();

            $recorder->recordRequest('PUT', 'https://api.example.com/3', []);
            expect($recorder->getRequestHistory())->toHaveCount(1);
        });

        it('reset on empty history does not cause errors', function () {
            $recorder = new RequestRecorder();
            $recorder->reset();

            $history = $recorder->getRequestHistory();

            expect($history)->toBeEmpty();
        });

        it('does not affect recording state', function () {
            $recorder = new RequestRecorder();
            $recorder->setRecordRequests(false);
            $recorder->reset();

            $recorder->recordRequest('GET', 'https://api.example.com/test', []);

            expect($recorder->getRequestHistory())->toBeEmpty();
        });
    });

    describe('getLastRequest', function () {
        it('returns null when no requests recorded', function () {
            $recorder = new RequestRecorder();

            $lastRequest = $recorder->getLastRequest();

            expect($lastRequest)->toBeNull();
        });

        it('returns the only request when one recorded', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/users', []);

            $lastRequest = $recorder->getLastRequest();

            expect($lastRequest)->toBeInstanceOf(RecordedRequest::class)
                ->and($lastRequest->method)->toBe('GET')
                ->and($lastRequest->url)->toBe('https://api.example.com/users')
            ;
        });

        it('returns the most recent request when multiple recorded', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/first', []);
            $recorder->recordRequest('POST', 'https://api.example.com/second', []);
            $recorder->recordRequest('PUT', 'https://api.example.com/last', []);

            $lastRequest = $recorder->getLastRequest();

            expect($lastRequest)->toBeInstanceOf(RecordedRequest::class)
                ->and($lastRequest->method)->toBe('PUT')
                ->and($lastRequest->url)->toBe('https://api.example.com/last')
            ;
        });

        it('updates after new request is recorded', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/1', []);
            expect($recorder->getLastRequest()->url)->toBe('https://api.example.com/1');

            $recorder->recordRequest('POST', 'https://api.example.com/2', []);
            expect($recorder->getLastRequest()->url)->toBe('https://api.example.com/2');
        });

        it('returns null after reset', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/users', []);
            $recorder->reset();

            $lastRequest = $recorder->getLastRequest();

            expect($lastRequest)->toBeNull();
        });
    });

    describe('getFirstRequest', function () {
        it('returns null when no requests recorded', function () {
            $recorder = new RequestRecorder();

            $firstRequest = $recorder->getFirstRequest();

            expect($firstRequest)->toBeNull();
        });

        it('returns the only request when one recorded', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/users', []);

            $firstRequest = $recorder->getFirstRequest();

            expect($firstRequest)->toBeInstanceOf(RecordedRequest::class)
                ->and($firstRequest->method)->toBe('GET')
                ->and($firstRequest->url)->toBe('https://api.example.com/users')
            ;
        });

        it('returns the first request when multiple recorded', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/first', []);
            $recorder->recordRequest('POST', 'https://api.example.com/second', []);
            $recorder->recordRequest('PUT', 'https://api.example.com/third', []);

            $firstRequest = $recorder->getFirstRequest();

            expect($firstRequest)->toBeInstanceOf(RecordedRequest::class)
                ->and($firstRequest->method)->toBe('GET')
                ->and($firstRequest->url)->toBe('https://api.example.com/first')
            ;
        });

        it('remains unchanged when new requests are recorded', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/first', []);
            $recorder->recordRequest('POST', 'https://api.example.com/second', []);

            $firstRequest = $recorder->getFirstRequest();

            expect($firstRequest->url)->toBe('https://api.example.com/first');
        });

        it('returns null after reset', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/users', []);
            $recorder->reset();

            $firstRequest = $recorder->getFirstRequest();

            expect($firstRequest)->toBeNull();
        });
    });

    describe('getRequest', function () {
        it('returns null when index does not exist', function () {
            $recorder = new RequestRecorder();

            $request = $recorder->getRequest(0);

            expect($request)->toBeNull();
        });

        it('returns null for negative index', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/users', []);

            $request = $recorder->getRequest(-1);

            expect($request)->toBeNull();
        });

        it('returns null for out of bounds index', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/users', []);

            $request = $recorder->getRequest(10);

            expect($request)->toBeNull();
        });

        it('returns request at index 0', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/first', []);

            $request = $recorder->getRequest(0);

            expect($request)->toBeInstanceOf(RecordedRequest::class)
                ->and($request->method)->toBe('GET')
                ->and($request->url)->toBe('https://api.example.com/first')
            ;
        });

        it('returns request at specific index', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/0', []);
            $recorder->recordRequest('POST', 'https://api.example.com/1', []);
            $recorder->recordRequest('PUT', 'https://api.example.com/2', []);

            $request = $recorder->getRequest(1);

            expect($request)->toBeInstanceOf(RecordedRequest::class)
                ->and($request->method)->toBe('POST')
                ->and($request->url)->toBe('https://api.example.com/1')
            ;
        });

        it('returns different requests for different indices', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/first', []);
            $recorder->recordRequest('POST', 'https://api.example.com/second', []);
            $recorder->recordRequest('PUT', 'https://api.example.com/third', []);

            expect($recorder->getRequest(0)->url)->toBe('https://api.example.com/first')
                ->and($recorder->getRequest(1)->url)->toBe('https://api.example.com/second')
                ->and($recorder->getRequest(2)->url)->toBe('https://api.example.com/third')
            ;
        });

        it('returns last request using count-1 as index', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('GET', 'https://api.example.com/1', []);
            $recorder->recordRequest('POST', 'https://api.example.com/2', []);
            $recorder->recordRequest('PUT', 'https://api.example.com/last', []);

            $request = $recorder->getRequest(2);

            expect($request)->toBeInstanceOf(RecordedRequest::class)
                ->and($request->url)->toBe('https://api.example.com/last')
            ;
        });
    });

    describe('Edge Cases', function () {
        it('handles recording many requests', function () {
            $recorder = new RequestRecorder();

            for ($i = 0; $i < 1000; $i++) {
                $recorder->recordRequest('GET', "https://api.example.com/resource/{$i}", []);
            }

            $history = $recorder->getRequestHistory();

            expect($history)->toHaveCount(1000)
                ->and($recorder->getFirstRequest()->url)->toBe('https://api.example.com/resource/0')
                ->and($recorder->getLastRequest()->url)->toBe('https://api.example.com/resource/999')
            ;
        });

        it('handles empty strings in method and URL', function () {
            $recorder = new RequestRecorder();
            $recorder->recordRequest('', '', []);

            $history = $recorder->getRequestHistory();

            expect($history[0]->method)->toBe('')
                ->and($history[0]->url)->toBe('')
            ;
        });

        it('handles special characters in URLs', function () {
            $recorder = new RequestRecorder();
            $url = 'https://api.example.com/search?q=hello%20world&filter=a+b';
            $recorder->recordRequest('GET', $url, []);

            $history = $recorder->getRequestHistory();

            expect($history[0]->url)->toBe($url);
        });

        it('handles very long URLs', function () {
            $recorder = new RequestRecorder();
            $longUrl = 'https://api.example.com/' . str_repeat('segment/', 100);
            $recorder->recordRequest('GET', $longUrl, []);

            $history = $recorder->getRequestHistory();

            expect($history[0]->url)->toBe($longUrl);
        });

        it('handles deeply nested options', function () {
            $recorder = new RequestRecorder();
            $options = [
                'level1' => [
                    'level2' => [
                        'level3' => [
                            'level4' => ['data' => 'value'],
                        ],
                    ],
                ],
            ];

            $recorder->recordRequest('POST', 'https://api.example.com/deep', $options);

            $history = $recorder->getRequestHistory();

            expect($history[0]->options)->toBe($options);
        });

        it('preserves array key types in options', function () {
            $recorder = new RequestRecorder();
            $options = [
                0 => 'numeric key',
                'string' => 'string key',
                1 => 'another numeric',
            ];

            $recorder->recordRequest('POST', 'https://api.example.com/test', $options);

            $history = $recorder->getRequestHistory();

            expect($history[0]->options[0])->toBe('numeric key')
                ->and($history[0]->options['string'])->toBe('string key')
                ->and($history[0]->options[1])->toBe('another numeric')
            ;
        });
    });

    describe('Integration Scenarios', function () {
        it('simulates a typical request flow', function () {
            $recorder = new RequestRecorder();

            // Login
            $recorder->recordRequest('POST', 'https://api.example.com/login', [
                'json' => ['username' => 'user', 'password' => 'pass'],
            ]);

            // Fetch data
            $recorder->recordRequest('GET', 'https://api.example.com/users', [
                'headers' => ['Authorization' => 'Bearer token'],
            ]);

            // Update data
            $recorder->recordRequest('PUT', 'https://api.example.com/users/1', [
                'json' => ['name' => 'Updated Name'],
            ]);

            // Logout
            $recorder->recordRequest('POST', 'https://api.example.com/logout', []);

            $history = $recorder->getRequestHistory();

            expect($history)->toHaveCount(4)
                ->and($recorder->getFirstRequest()->url)->toBe('https://api.example.com/login')
                ->and($recorder->getLastRequest()->url)->toBe('https://api.example.com/logout')
            ;
        });

        it('can pause recording during sensitive operations', function () {
            $recorder = new RequestRecorder();

            $recorder->recordRequest('GET', 'https://api.example.com/public', []);

            // Pause recording for sensitive request
            $recorder->setRecordRequests(false);
            $recorder->recordRequest('POST', 'https://api.example.com/sensitive', [
                'password' => 'secret',
            ]);

            // Resume recording
            $recorder->setRecordRequests(true);
            $recorder->recordRequest('GET', 'https://api.example.com/public2', []);

            $history = $recorder->getRequestHistory();

            expect($history)->toHaveCount(2)
                ->and($history[0]->url)->toBe('https://api.example.com/public')
                ->and($history[1]->url)->toBe('https://api.example.com/public2')
            ;
        });

        it('can reset between test scenarios', function () {
            $recorder = new RequestRecorder();

            // First scenario
            $recorder->recordRequest('GET', 'https://api.example.com/scenario1', []);
            expect($recorder->getRequestHistory())->toHaveCount(1);

            $recorder->reset();

            // Second scenario
            $recorder->recordRequest('POST', 'https://api.example.com/scenario2', []);
            expect($recorder->getRequestHistory())->toHaveCount(1)
                ->and($recorder->getFirstRequest()->url)->toBe('https://api.example.com/scenario2')
            ;
        });
    });
});
