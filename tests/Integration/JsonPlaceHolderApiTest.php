<?php

use Hibla\HttpClient\Handlers\HttpHandler;
use Hibla\HttpClient\RetryConfig;

describe('Real API Integration Tests', function () {

    test('fetches a single post from JSONPlaceholder', function () {
        $handler = new HttpHandler();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/posts/1');
        $response = $promise->await();

        expect($response->status())->toBe(200)
            ->and($response->successful())->toBeTrue()
            ->and($response->json())->toHaveKey('id', 1)
            ->and($response->json())->toHaveKey('userId')
            ->and($response->json())->toHaveKey('title')
            ->and($response->json())->toHaveKey('body');
    });

    test('fetches all posts from JSONPlaceholder', function () {
        $handler = new HttpHandler();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/posts');
        $response = $promise->await();

        $posts = $response->json();

        expect($response->status())->toBe(200)
            ->and($posts)->toBeArray()
            ->and(count($posts))->toBeGreaterThan(0)
            ->and($posts[0])->toHaveKey('id')
            ->and($posts[0])->toHaveKey('title');
    });

    test('creates a new post via POST request', function () {
        $handler = new HttpHandler();

        $postData = [
            'title' => 'Integration Test Post',
            'body' => 'This is a test post from our integration tests',
            'userId' => 1,
        ];

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/posts', [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'json' => $postData,
        ]);

        $response = $promise->await();

        expect($response->status())->toBe(201)
            ->and($response->json())->toHaveKey('id')
            ->and($response->json('title'))->toBe('Integration Test Post')
            ->and($response->json('body'))->toBe('This is a test post from our integration tests')
            ->and($response->json('userId'))->toBe(1);
    });

    test('updates a post via PUT request', function () {
        $handler = new HttpHandler();

        $updatedData = [
            'id' => 1,
            'title' => 'Updated Title',
            'body' => 'Updated body content',
            'userId' => 1,
        ];

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/posts/1', [
            'method' => 'PUT',
            'headers' => ['Content-Type' => 'application/json'],
            'json' => $updatedData,
        ]);

        $response = $promise->await();

        expect($response->status())->toBe(200)
            ->and($response->json('title'))->toBe('Updated Title')
            ->and($response->json('body'))->toBe('Updated body content');
    });

    test('patches a post via PATCH request', function () {
        $handler = new HttpHandler();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/posts/1', [
            'method' => 'PATCH',
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['title' => 'Patched Title'],
        ]);

        $response = $promise->await();

        expect($response->status())->toBe(200)
            ->and($response->json('title'))->toBe('Patched Title');
    });

    test('deletes a post via DELETE request', function () {
        $handler = new HttpHandler();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/posts/1', [
            'method' => 'DELETE',
        ]);

        $response = $promise->await();

        expect($response->status())->toBe(200);
    });

    test('fetches nested resource - comments for a post', function () {
        $handler = new HttpHandler();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/posts/1/comments');
        $response = $promise->await();

        $comments = $response->json();

        expect($response->status())->toBe(200)
            ->and($comments)->toBeArray()
            ->and(count($comments))->toBeGreaterThan(0)
            ->and($comments[0])->toHaveKey('postId', 1)
            ->and($comments[0])->toHaveKey('email')
            ->and($comments[0])->toHaveKey('body');
    });

    test('filters posts by query parameters', function () {
        $handler = new HttpHandler();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/posts?userId=1');
        $response = $promise->await();

        $posts = $response->json();

        expect($response->status())->toBe(200)
            ->and($posts)->toBeArray()
            ->and(count($posts))->toBeGreaterThan(0);

        foreach ($posts as $post) {
            expect($post['userId'])->toBe(1);
        }
    });

    test('handles 404 not found error', function () {
        $handler = new HttpHandler();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/posts/99999');
        $response = $promise->await();

        expect($response->status())->toBe(404)
            ->and($response->failed())->toBeTrue()
            ->and($response->clientError())->toBeTrue()
            ->and($response->successful())->toBeFalse();
    });

    test('fetches user data', function () {
        $handler = new HttpHandler();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/users/1');
        $response = $promise->await();

        expect($response->status())->toBe(200)
            ->and($response->json())->toHaveKey('id', 1)
            ->and($response->json())->toHaveKey('name')
            ->and($response->json())->toHaveKey('email')
            ->and($response->json())->toHaveKey('address');
    });

    test('extracts nested JSON data using dot notation', function () {
        $handler = new HttpHandler();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/users/1');
        $response = $promise->await();

        expect($response->json('address.city'))->toBeString()
            ->and($response->json('address.geo.lat'))->toBeString()
            ->and($response->json('company.name'))->toBeString();
    });

    test('handles response headers correctly', function () {
        $handler = new HttpHandler();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/posts/1');
        $response = $promise->await();

        expect($response->header('content-type'))->toContain('application/json')
            ->and($response->headers())->toHaveKey('content-type')
            ->and($response->headers())->toHaveKey('date');
    });

    test('fetches albums with nested photos', function () {
        $handler = new HttpHandler();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/albums/1/photos');
        $response = $promise->await();

        $photos = $response->json();

        expect($response->status())->toBe(200)
            ->and($photos)->toBeArray()
            ->and(count($photos))->toBeGreaterThan(0)
            ->and($photos[0])->toHaveKey('albumId', 1)
            ->and($photos[0])->toHaveKey('thumbnailUrl')
            ->and($photos[0])->toHaveKey('url');
    });

    test('fetches todos', function () {
        $handler = new HttpHandler();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/todos/1');
        $response = $promise->await();

        expect($response->status())->toBe(200)
            ->and($response->json())->toHaveKey('userId')
            ->and($response->json())->toHaveKey('title')
            ->and($response->json())->toHaveKey('completed')
            ->and($response->json('completed'))->toBeBool();
    });

    test('handles multiple sequential requests', function () {
        $handler = new HttpHandler();

        $userPromise = $handler->fetch('https://jsonplaceholder.typicode.com/users/1');
        $userResponse = $userPromise->await();
        $userId = $userResponse->json('id');

        $postsPromise = $handler->fetch("https://jsonplaceholder.typicode.com/posts?userId={$userId}");
        $postsResponse = $postsPromise->await();

        $todosPromise = $handler->fetch("https://jsonplaceholder.typicode.com/todos?userId={$userId}");
        $todosResponse = $todosPromise->await();

        expect($userResponse->status())->toBe(200)
            ->and($postsResponse->status())->toBe(200)
            ->and($todosResponse->status())->toBe(200)
            ->and($postsResponse->json())->toBeArray()
            ->and($todosResponse->json())->toBeArray();
    });

    test('sends custom headers', function () {
        $handler = new HttpHandler();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/posts/1', [
            'headers' => [
                'X-Custom-Header' => 'test-value',
                'Accept' => 'application/json',
            ],
        ]);

        $response = $promise->await();

        expect($response->status())->toBe(200)
            ->and($response->json())->toHaveKey('id');
    });

    test('handles timeout configuration', function () {
        $handler = new HttpHandler();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/posts/1', [
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);

        $response = $promise->await();

        expect($response->status())->toBe(200);
    });

    test('validates response body as string', function () {
        $handler = new HttpHandler();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/posts/1');
        $response = $promise->await();

        $body = $response->body();

        expect($body)->toBeString()
            ->and(strlen($body))->toBeGreaterThan(0)
            ->and($body)->toContain('"id"')
            ->and($body)->toContain('"title"');
    });

    test('checks HTTP version information', function () {
        $handler = new HttpHandler();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/posts/1');
        $response = $promise->await();

        $httpVersion = $response->getHttpVersion();

        expect($response->status())->toBe(200)
            ->and($httpVersion)->not->toBeNull();
    });

    test('handles JSON default value when key not found', function () {
        $handler = new HttpHandler();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/posts/1');
        $response = $promise->await();

        expect($response->json('nonexistent.key', 'default'))->toBe('default')
            ->and($response->json('deeply.nested.key', 'fallback'))->toBe('fallback');
    });

    test('verifies response status checks', function () {
        $handler = new HttpHandler();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/posts/1');
        $response = $promise->await();

        expect($response->successful())->toBeTrue()
            ->and($response->failed())->toBeFalse()
            ->and($response->clientError())->toBeFalse()
            ->and($response->serverError())->toBeFalse();

        $promise404 = $handler->fetch('https://jsonplaceholder.typicode.com/posts/99999');
        $response404 = $promise404->await();

        expect($response404->successful())->toBeFalse()
            ->and($response404->failed())->toBeTrue()
            ->and($response404->clientError())->toBeTrue()
            ->and($response404->serverError())->toBeFalse();
    });

    test('fetches comments with email validation', function () {
        $handler = new HttpHandler();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/comments/1');
        $response = $promise->await();

        $email = $response->json('email');

        expect($response->status())->toBe(200)
            ->and($email)->toBeString()
            ->and(filter_var($email, FILTER_VALIDATE_EMAIL))->not->toBeFalse();
    });

    test('posts form data', function () {
        $handler = new HttpHandler();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/posts', [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => http_build_query([
                'title' => 'Form Data Post',
                'body' => 'Posted via form data',
                'userId' => 1,
            ]),
        ]);

        $response = $promise->await();

        expect($response->status())->toBe(201);
    });

    test('handles response with different HTTP methods', function () {
        $handler = new HttpHandler();

        $testCases = [
            'GET' => ['url' => 'https://jsonplaceholder.typicode.com/posts/1', 'expectedStatus' => 200],
            'POST' => ['url' => 'https://jsonplaceholder.typicode.com/posts', 'expectedStatus' => 201, 'json' => true],
            'PUT' => ['url' => 'https://jsonplaceholder.typicode.com/posts/1', 'expectedStatus' => 200, 'json' => true],
            'PATCH' => ['url' => 'https://jsonplaceholder.typicode.com/posts/1', 'expectedStatus' => 200, 'json' => true],
            'DELETE' => ['url' => 'https://jsonplaceholder.typicode.com/posts/1', 'expectedStatus' => 200],
        ];

        foreach ($testCases as $method => $config) {
            $options = ['method' => $method];

            if (isset($config['json'])) {
                $options['json'] = ['title' => 'Test', 'body' => 'Test', 'userId' => 1];
            }

            $promise = $handler->fetch($config['url'], $options);
            $response = $promise->await();

            expect($response->status())->toBe($config['expectedStatus']);
        }
    });
})->skipOnCI();


describe('Mock Handler Integration Tests', function () {

    test('simulates JSONPlaceholder API with mocks', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/1')
            ->respondJson([
                'userId' => 1,
                'id' => 1,
                'title' => 'Mocked Post Title',
                'body' => 'Mocked post body content',
            ])
            ->register();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/posts/1');
        $response = $promise->await();

        expect($response->status())->toBe(200)
            ->and($response->json('title'))->toBe('Mocked Post Title')
            ->and($response->json('id'))->toBe(1);
    });

    test('simulates slow API response', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts')
            ->delay(0.5)
            ->respondJson([
                ['id' => 1, 'title' => 'Post 1'],
                ['id' => 2, 'title' => 'Post 2'],
            ])
            ->register();

        $start = microtime(true);
        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/posts');
        $response = $promise->await();
        $duration = microtime(true) - $start;

        expect($duration)->toBeGreaterThanOrEqual(0.5)
            ->and($response->json())->toHaveCount(2);
    });

    test('simulates rate limiting scenario', function () {
        $handler = testingHttpHandler();

        $handler->mock('POST')
            ->url('https://jsonplaceholder.typicode.com/posts')
            ->rateLimitedUntilAttempt(3)
            ->register();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/posts', [
            'method' => 'POST',
            'json' => ['title' => 'Test', 'body' => 'Test', 'userId' => 1],
            'retry' => new RetryConfig(maxRetries: 5, baseDelay: 0.01),
        ]);

        $response = $promise->await();

        expect($response->status())->toBe(200)
            ->and($response->json())->toHaveKey('success', true);
    });

    test('simulates network recovery scenario', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://jsonplaceholder.typicode.com/users/1')
            ->slowlyImproveUntilAttempt(3, 2.0)
            ->register();

        $promise = $handler->fetch('https://jsonplaceholder.typicode.com/users/1', [
            'retry' => new RetryConfig(maxRetries: 5, baseDelay: 0.01),
        ]);

        $response = $promise->await();

        expect($response->status())->toBe(200)
            ->and($response->json())->toHaveKey('success', true);
    });

    test('simulates persistent mock for multiple requests', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/*')
            ->respondJson(['id' => 1, 'title' => 'Generic Post'])
            ->persistent()
            ->register();

        $promise1 = $handler->fetch('https://jsonplaceholder.typicode.com/posts/1');
        $response1 = $promise1->await();

        $promise2 = $handler->fetch('https://jsonplaceholder.typicode.com/posts/2');
        $response2 = $promise2->await();

        $promise3 = $handler->fetch('https://jsonplaceholder.typicode.com/posts/3');
        $response3 = $promise3->await();

        expect($response1->json('title'))->toBe('Generic Post')
            ->and($response2->json('title'))->toBe('Generic Post')
            ->and($response3->json('title'))->toBe('Generic Post')
            ->and($handler->getRequestHistory())->toHaveCount(3);
    });
});
