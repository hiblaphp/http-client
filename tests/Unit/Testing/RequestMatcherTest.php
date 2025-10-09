<?php

use Hibla\HttpClient\Testing\Utilities\RequestMatcher;
use Hibla\HttpClient\Testing\Utilities\RecordedRequest;
use Hibla\HttpClient\Testing\MockedRequest;

describe('RequestMatcher', function () {

    describe('findMatchingMock', function () {
        it('finds a matching mock by method and URL', function () {
            $matcher = new RequestMatcher();
            
            $mock1 = Mockery::mock(MockedRequest::class);
            $mock1->shouldReceive('matches')->with('GET', 'https://api.example.com/users', [])->andReturn(false);
            
            $mock2 = Mockery::mock(MockedRequest::class);
            $mock2->shouldReceive('matches')->with('GET', 'https://api.example.com/users', [])->andReturn(true);
            
            $mocks = [$mock1, $mock2];
            
            $result = $matcher->findMatchingMock($mocks, 'GET', 'https://api.example.com/users', []);
            
            expect($result)->toBeArray()
                ->and($result['mock'])->toBe($mock2)
                ->and($result['index'])->toBe(1);
        });

        it('returns first matching mock when multiple mocks match', function () {
            $matcher = new RequestMatcher();
            
            $mock1 = Mockery::mock(MockedRequest::class);
            $mock1->shouldReceive('matches')->with('POST', 'https://api.example.com/data', [])->andReturn(true);
            
            $mock2 = Mockery::mock(MockedRequest::class);
            $mock2->shouldReceive('matches')->never();
            
            $mocks = [$mock1, $mock2];
            
            $result = $matcher->findMatchingMock($mocks, 'POST', 'https://api.example.com/data', []);
            
            expect($result)->toBeArray()
                ->and($result['mock'])->toBe($mock1)
                ->and($result['index'])->toBe(0);
        });

        it('returns null when no mocks match', function () {
            $matcher = new RequestMatcher();
            
            $mock1 = Mockery::mock(MockedRequest::class);
            $mock1->shouldReceive('matches')->with('GET', 'https://api.example.com/missing', [])->andReturn(false);
            
            $mock2 = Mockery::mock(MockedRequest::class);
            $mock2->shouldReceive('matches')->with('GET', 'https://api.example.com/missing', [])->andReturn(false);
            
            $mocks = [$mock1, $mock2];
            
            $result = $matcher->findMatchingMock($mocks, 'GET', 'https://api.example.com/missing', []);
            
            expect($result)->toBeNull();
        });

        it('returns null when mocks array is empty', function () {
            $matcher = new RequestMatcher();
            
            $result = $matcher->findMatchingMock([], 'GET', 'https://api.example.com/users', []);
            
            expect($result)->toBeNull();
        });

        it('passes options to mock matches method', function () {
            $matcher = new RequestMatcher();
            
            $options = ['headers' => ['Authorization' => 'Bearer token']];
            
            $mock = Mockery::mock(MockedRequest::class);
            $mock->shouldReceive('matches')
                ->with('POST', 'https://api.example.com/data', $options)
                ->andReturn(true);
            
            $mocks = [$mock];
            
            $result = $matcher->findMatchingMock($mocks, 'POST', 'https://api.example.com/data', $options);
            
            expect($result)->toBeArray()
                ->and($result['mock'])->toBe($mock)
                ->and($result['index'])->toBe(0);
        });

        it('correctly returns index for middle mock in array', function () {
            $matcher = new RequestMatcher();
            
            $mock1 = Mockery::mock(MockedRequest::class);
            $mock1->shouldReceive('matches')->andReturn(false);
            
            $mock2 = Mockery::mock(MockedRequest::class);
            $mock2->shouldReceive('matches')->andReturn(false);
            
            $mock3 = Mockery::mock(MockedRequest::class);
            $mock3->shouldReceive('matches')->andReturn(true);
            
            $mocks = [$mock1, $mock2, $mock3];
            
            $result = $matcher->findMatchingMock($mocks, 'GET', 'https://example.com', []);
            
            expect($result['index'])->toBe(2);
        });
    });

    describe('matchesRequest', function () {
        it('matches exact method and URL', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/users', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://api.example.com/users');
            
            expect($matches)->toBeTrue();
        });

        it('does not match different method', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/users', []);
            
            $matches = $matcher->matchesRequest($request, 'POST', 'https://api.example.com/users');
            
            expect($matches)->toBeFalse();
        });

        it('does not match different URL', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/users', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://api.example.com/posts');
            
            expect($matches)->toBeFalse();
        });

        it('matches wildcard method with asterisk', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('POST', 'https://api.example.com/data', []);
            
            $matches = $matcher->matchesRequest($request, '*', 'https://api.example.com/data');
            
            expect($matches)->toBeTrue();
        });

        it('matches with wildcard pattern in URL', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/users/123', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://api.example.com/users/*');
            
            expect($matches)->toBeTrue();
        });

        it('matches with multiple wildcards in URL', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/users/123/posts/456', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://api.example.com/users/*/posts/*');
            
            expect($matches)->toBeTrue();
        });

        it('matches URL with trailing slash when pattern has no trailing slash', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/users/', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://api.example.com/users');
            
            expect($matches)->toBeTrue();
        });

        it('matches URL without trailing slash when pattern has trailing slash', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/users', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://api.example.com/users/');
            
            expect($matches)->toBeTrue();
        });

        it('matches both URLs with trailing slashes', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/users/', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://api.example.com/users/');
            
            expect($matches)->toBeTrue();
        });

        it('matches both URLs without trailing slashes', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/users', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://api.example.com/users');
            
            expect($matches)->toBeTrue();
        });

        it('accepts options parameter', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('POST', 'https://api.example.com/data', []);
            
            $matches = $matcher->matchesRequest(
                $request, 
                'POST', 
                'https://api.example.com/data',
                ['headers' => ['Content-Type' => 'application/json']]
            );
            
            expect($matches)->toBeTrue();
        });

        it('works with different HTTP methods', function () {
            $matcher = new RequestMatcher();
            $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
            
            foreach ($methods as $method) {
                $request = new RecordedRequest($method, 'https://api.example.com/resource', []);
                $matches = $matcher->matchesRequest($request, $method, 'https://api.example.com/resource');
                
                expect($matches)->toBeTrue();
            }
        });
    });

    describe('URL Pattern Matching', function () {
        it('matches question mark wildcard for single character', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/user1', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://api.example.com/user?');
            
            expect($matches)->toBeTrue();
        });

        it('does not match question mark wildcard for multiple characters', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/user123', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://api.example.com/user?');
            
            expect($matches)->toBeFalse();
        });

        it('matches character class pattern', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/user1', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://api.example.com/user[0-9]');
            
            expect($matches)->toBeTrue();
        });

        it('matches complex pattern with multiple wildcards', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/v1/users/john/posts/123', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://api.example.com/*/users/*/posts/*');
            
            expect($matches)->toBeTrue();
        });

        it('does not match when pattern is more specific', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/users', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://api.example.com/users/123');
            
            expect($matches)->toBeFalse();
        });

        it('handles query parameters in URL', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/users?page=1&limit=10', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://api.example.com/users?*');
            
            expect($matches)->toBeTrue();
        });

        it('matches exact query parameters', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/users?page=1', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://api.example.com/users?page=1');
            
            expect($matches)->toBeTrue();
        });
    });

    describe('Edge Cases', function () {
        it('handles empty URL', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', '', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', '');
            
            expect($matches)->toBeTrue();
        });

        it('handles root path URL', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', '/', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', '/');
            
            expect($matches)->toBeTrue();
        });

        it('handles root path with and without trailing slash', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', '/', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', '');
            
            expect($matches)->toBeTrue();
        });

        it('handles URL with port number', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com:8080/users', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://api.example.com:8080/users');
            
            expect($matches)->toBeTrue();
        });

        it('handles URL with fragment', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/users#section', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://api.example.com/users#section');
            
            expect($matches)->toBeTrue();
        });

        it('handles URL with authentication', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://user:pass@api.example.com/users', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://user:pass@api.example.com/users');
            
            expect($matches)->toBeTrue();
        });

        it('case sensitive URL matching', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/Users', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://api.example.com/users');
            
            expect($matches)->toBeFalse();
        });

        it('case sensitive method matching', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/users', []);
            
            $matches = $matcher->matchesRequest($request, 'get', 'https://api.example.com/users');
            
            expect($matches)->toBeFalse();
        });

        it('handles very long URLs', function () {
            $matcher = new RequestMatcher();
            $longPath = str_repeat('/segment', 100);
            $request = new RecordedRequest('GET', "https://api.example.com{$longPath}", []);
            
            $matches = $matcher->matchesRequest($request, 'GET', "https://api.example.com{$longPath}");
            
            expect($matches)->toBeTrue();
        });

        it('handles special characters in URL', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/users/john%20doe', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://api.example.com/users/john%20doe');
            
            expect($matches)->toBeTrue();
        });
    });

    describe('Integration Scenarios', function () {
        it('finds correct mock in mixed method requests', function () {
            $matcher = new RequestMatcher();
            
            $getMock = Mockery::mock(MockedRequest::class);
            $getMock->shouldReceive('matches')->with('GET', 'https://api.example.com/users', [])->andReturn(true);
            
            $postMock = Mockery::mock(MockedRequest::class);
            $postMock->shouldReceive('matches')->with('GET', 'https://api.example.com/users', [])->andReturn(false);
            
            $mocks = [$postMock, $getMock];
            
            $result = $matcher->findMatchingMock($mocks, 'GET', 'https://api.example.com/users', []);
            
            expect($result['mock'])->toBe($getMock)
                ->and($result['index'])->toBe(1);
        });

        it('matches wildcard method with any HTTP method', function () {
            $matcher = new RequestMatcher();
            $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
            
            foreach ($methods as $method) {
                $request = new RecordedRequest($method, 'https://api.example.com/resource', []);
                $matches = $matcher->matchesRequest($request, '*', 'https://api.example.com/resource');
                
                expect($matches)->toBeTrue();
            }
        });

        it('handles trailing slash variations with wildcards', function () {
            $matcher = new RequestMatcher();
            $request = new RecordedRequest('GET', 'https://api.example.com/users/123/', []);
            
            $matches = $matcher->matchesRequest($request, 'GET', 'https://api.example.com/users/*');
            
            expect($matches)->toBeTrue();
        });
    });
});