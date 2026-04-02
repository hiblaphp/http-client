<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Hibla\HttpClient\HttpClient;

describe('Real API Integration Tests', function () {

    test('fetches a single post from JSONPlaceholder', function () {
        $response = Http::get('https://jsonplaceholder.typicode.com/posts/1')->wait();

        expect($response->status())->toBe(200)
            ->and($response->successful())->toBeTrue()
            ->and($response->json())->toHaveKey('id', 1)
            ->and($response->json())->toHaveKey('userId')
            ->and($response->json())->toHaveKey('title')
            ->and($response->json())->toHaveKey('body')
        ;
    });

    test('fetches all posts from JSONPlaceholder', function () {
        $response = Http::get('https://jsonplaceholder.typicode.com/posts')->wait();

        $posts = $response->json();

        expect($response->status())->toBe(200)
            ->and($posts)->toBeArray()
            ->and(count($posts))->toBeGreaterThan(0)
            ->and($posts[0])->toHaveKey('id')
            ->and($posts[0])->toHaveKey('title')
        ;
    });

    test('creates a new post via POST request', function () {
        $postData = [
            'title' => 'Integration Test Post',
            'body' => 'This is a test post from our integration tests',
            'userId' => 1,
        ];

        $response = Http::withJson($postData)
            ->post('https://jsonplaceholder.typicode.com/posts')
            ->wait()
        ;

        expect($response->status())->toBe(201)
            ->and($response->json())->toHaveKey('id')
            ->and($response->json('title'))->toBe('Integration Test Post')
            ->and($response->json('body'))->toBe('This is a test post from our integration tests')
            ->and($response->json('userId'))->toBe(1)
        ;
    });

    test('updates a post via PUT request', function () {
        $updatedData = [
            'id' => 1,
            'title' => 'Updated Title',
            'body' => 'Updated body content',
            'userId' => 1,
        ];

        $response = Http::withJson($updatedData)
            ->put('https://jsonplaceholder.typicode.com/posts/1')
            ->wait()
        ;

        expect($response->status())->toBe(200)
            ->and($response->json('title'))->toBe('Updated Title')
            ->and($response->json('body'))->toBe('Updated body content')
        ;
    });

    test('patches a post via PATCH request', function () {
        $response = Http::withJson(['title' => 'Patched Title'])
            ->patch('https://jsonplaceholder.typicode.com/posts/1')
            ->wait()
        ;

        expect($response->status())->toBe(200)
            ->and($response->json('title'))->toBe('Patched Title')
        ;
    });

    test('deletes a post via DELETE request', function () {
        $response = Http::delete('https://jsonplaceholder.typicode.com/posts/1')->wait();

        expect($response->status())->toBe(200);
    });

    test('fetches nested resource - comments for a post', function () {
        $response = Http::get('https://jsonplaceholder.typicode.com/posts/1/comments')->wait();

        $comments = $response->json();

        expect($response->status())->toBe(200)
            ->and($comments)->toBeArray()
            ->and(count($comments))->toBeGreaterThan(0)
            ->and($comments[0])->toHaveKey('postId', 1)
            ->and($comments[0])->toHaveKey('email')
            ->and($comments[0])->toHaveKey('body')
        ;
    });

    test('filters posts by query parameters', function () {
        $response = Http::get('https://jsonplaceholder.typicode.com/posts', ['userId' => 1])->wait();

        $posts = $response->json();

        expect($response->status())->toBe(200)
            ->and($posts)->toBeArray()
            ->and(count($posts))->toBeGreaterThan(0)
        ;

        foreach ($posts as $post) {
            expect($post['userId'])->toBe(1);
        }
    });

    test('handles 404 not found error', function () {
        $response = Http::get('https://jsonplaceholder.typicode.com/posts/99999')->wait();

        expect($response->status())->toBe(404)
            ->and($response->failed())->toBeTrue()
            ->and($response->clientError())->toBeTrue()
            ->and($response->successful())->toBeFalse()
        ;
    });

    test('extracts nested JSON data using dot notation', function () {
        $response = Http::get('https://jsonplaceholder.typicode.com/users/1')->wait();

        expect($response->json('address.city'))->toBeString()
            ->and($response->json('address.geo.lat'))->toBeString()
            ->and($response->json('company.name'))->toBeString()
        ;
    });

    test('handles response headers correctly', function () {
        $response = Http::get('https://jsonplaceholder.typicode.com/posts/1')->wait();

        expect($response->header('content-type'))->toContain('application/json')
            ->and($response->headers())->toHaveKey('content-type')
            ->and($response->headers())->toHaveKey('date')
        ;
    });

    test('handles multiple sequential requests', function () {
        $userResponse = Http::get('https://jsonplaceholder.typicode.com/users/1')->wait();
        $userId = $userResponse->json('id');

        $postsResponse = Http::get('https://jsonplaceholder.typicode.com/posts', ['userId' => $userId])->wait();
        $todosResponse = Http::get('https://jsonplaceholder.typicode.com/todos', ['userId' => $userId])->wait();

        expect($userResponse->status())->toBe(200)
            ->and($postsResponse->status())->toBe(200)
            ->and($todosResponse->status())->toBe(200)
        ;
    });

    test('sends custom headers', function () {
        $response = Http::withHeaders([
                'X-Custom-Header' => 'test-value',
                'Accept' => 'application/json',
            ])
            ->get('https://jsonplaceholder.typicode.com/posts/1')
            ->wait()
        ;

        expect($response->status())->toBe(200)
            ->and($response->json())->toHaveKey('id')
        ;
    });

    test('handles timeout configuration', function () {
        $response = Http::timeout(30)
            ->connectTimeout(10)
            ->get('https://jsonplaceholder.typicode.com/posts/1')
            ->wait()
        ;

        expect($response->status())->toBe(200);
    });

    test('validates response body as string', function () {
        $response = Http::get('https://jsonplaceholder.typicode.com/posts/1')->wait();

        $body = $response->body();

        expect($body)->toBeString()
            ->and(strlen($body))->toBeGreaterThan(0)
            ->and($body)->toContain('"id"')
            ->and($body)->toContain('"title"')
        ;
    });

    test('checks HTTP version information', function () {
        $response = Http::get('https://jsonplaceholder.typicode.com/posts/1')->wait();

        $httpVersion = $response->getHttpVersion();

        expect($response->status())->toBe(200)
            ->and($httpVersion)->not->toBeNull()
        ;
    });

    test('posts form data', function () {
        $response = Http::asForm()
            ->withForm([
                'title' => 'Form Data Post',
                'body' => 'Posted via form data',
                'userId' => 1,
            ])
            ->post('https://jsonplaceholder.typicode.com/posts')
            ->wait()
        ;

        expect($response->status())->toBe(201);
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
            ->register()
        ;

        $response = (new HttpClient())
            ->setHandler($handler)
            ->get('https://jsonplaceholder.typicode.com/posts/1')
            ->wait()
        ;

        expect($response->status())->toBe(200)
            ->and($response->json('title'))->toBe('Mocked Post Title')
            ->and($response->json('id'))->toBe(1)
        ;
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
            ->register()
        ;

        $start = microtime(true);

        $response = (new HttpClient())
            ->setHandler($handler)
            ->get('https://jsonplaceholder.typicode.com/posts')
            ->wait()
        ;

        $duration = microtime(true) - $start;

        expect($duration)->toBeGreaterThanOrEqual(0.5)
            ->and($response->json())->toHaveCount(2)
        ;
    });

    test('simulates rate limiting scenario', function () {
        $handler = testingHttpHandler();

        $handler->mock('POST')
            ->url('https://jsonplaceholder.typicode.com/posts')
            ->rateLimitedUntilAttempt(3)
            ->register()
        ;

        $response = (new HttpClient())
            ->setHandler($handler)
            ->retry(5, 0.01)
            ->withJson(['title' => 'Test', 'body' => 'Test', 'userId' => 1])
            ->post('https://jsonplaceholder.typicode.com/posts')
            ->wait()
        ;

        expect($response->status())->toBe(200)
            ->and($response->json())->toHaveKey('success', true)
        ;
    });

    test('simulates network recovery scenario', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://jsonplaceholder.typicode.com/users/1')
            ->slowlyImproveUntilAttempt(3, 2.0)
            ->register()
        ;

        $response = (new HttpClient())
            ->setHandler($handler)
            ->retry(5, 0.01)
            ->get('https://jsonplaceholder.typicode.com/users/1')
            ->wait()
        ;

        expect($response->status())->toBe(200)
            ->and($response->json())->toHaveKey('success', true)
        ;
    });

    test('simulates persistent mock for multiple requests', function () {
        $handler = testingHttpHandler();

        $handler->mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/*')
            ->respondJson(['id' => 1, 'title' => 'Generic Post'])
            ->persistent()
            ->register()
        ;

        $client = (new HttpClient())->setHandler($handler);

        $response1 = $client->get('https://jsonplaceholder.typicode.com/posts/1')->wait();
        $response2 = $client->get('https://jsonplaceholder.typicode.com/posts/2')->wait();
        $response3 = $client->get('https://jsonplaceholder.typicode.com/posts/3')->wait();

        expect($response1->json('title'))->toBe('Generic Post')
            ->and($response2->json('title'))->toBe('Generic Post')
            ->and($response3->json('title'))->toBe('Generic Post')
            ->and($handler->getRequestHistory())->toHaveCount(3)
        ;
    });
});
