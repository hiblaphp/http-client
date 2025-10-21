<?php

use Hibla\HttpClient\Http;
use Hibla\Promise\Promise;

beforeEach(function () {
    Http::startTesting()->withGlobalRandomDelay(0.1, 0.3);
});

afterEach(function () {
    Http::stopTesting();
});

describe('Promise::all() with Mocks using Http Facade', function () {
    test('fetches multiple posts concurrently with Promise::all()', function () {
        for ($i = 1; $i <= 3; $i++) {
            Http::mock('GET')
                ->url("https://jsonplaceholder.typicode.com/posts/{$i}")
                ->respondJson([
                    'userId' => 1,
                    'id' => $i,
                    'title' => "Post {$i} title",
                    'body' => "Post {$i} body",
                ])
                ->persistent()
                ->register()
            ;
        }

        $promises = [
            Http::get('https://jsonplaceholder.typicode.com/posts/1'),
            Http::get('https://jsonplaceholder.typicode.com/posts/2'),
            Http::get('https://jsonplaceholder.typicode.com/posts/3'),
        ];

        $results = Promise::all($promises)->await();

        expect($results)->toBeArray()
            ->and($results)->toHaveCount(3)
            ->and($results[0]->json('id'))->toBe(1)
            ->and($results[1]->json('id'))->toBe(2)
            ->and($results[2]->json('id'))->toBe(3)
            ->and($results[0]->successful())->toBeTrue()
            ->and($results[1]->successful())->toBeTrue()
            ->and($results[2]->successful())->toBeTrue()
        ;
    });

    test('fetches multiple different resources concurrently', function () {
        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/1')
            ->respondJson([
                'id' => 1,
                'title' => 'Post title',
                'body' => 'Post body',
                'userId' => 1,
            ])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/users/1')
            ->respondJson([
                'id' => 1,
                'name' => 'John Doe',
                'email' => 'john@example.com',
            ])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/comments/1')
            ->respondJson([
                'id' => 1,
                'email' => 'commenter@example.com',
                'body' => 'Comment body',
            ])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/todos/1')
            ->respondJson([
                'id' => 1,
                'completed' => false,
                'title' => 'Todo title',
            ])
            ->register()
        ;

        $promises = [
            'post' => Http::get('https://jsonplaceholder.typicode.com/posts/1'),
            'user' => Http::get('https://jsonplaceholder.typicode.com/users/1'),
            'comment' => Http::get('https://jsonplaceholder.typicode.com/comments/1'),
            'todo' => Http::get('https://jsonplaceholder.typicode.com/todos/1'),
        ];

        $results = Promise::all($promises)->await();

        expect($results)->toBeArray()
            ->and($results)->toHaveCount(4)
            ->and($results['post']->json())->toHaveKey('title')
            ->and($results['user']->json())->toHaveKey('name')
            ->and($results['comment']->json())->toHaveKey('email')
            ->and($results['todo']->json())->toHaveKey('completed')
        ;
    });

    test('Promise::all() handles when one request fails', function () {
        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/1')
            ->respondJson(['id' => 1, 'title' => 'Post 1'])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/99999')
            ->respondWithStatus(404)
            ->respondJson(['error' => 'Not found'])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/3')
            ->respondJson(['id' => 3, 'title' => 'Post 3'])
            ->register()
        ;

        $promises = [
            Http::get('https://jsonplaceholder.typicode.com/posts/1'),
            Http::get('https://jsonplaceholder.typicode.com/posts/99999'),
            Http::get('https://jsonplaceholder.typicode.com/posts/3'),
        ];

        $results = Promise::all($promises)->await();

        expect($results)->toHaveCount(3)
            ->and($results[0]->successful())->toBeTrue()
            ->and($results[1]->status())->toBe(404)
            ->and($results[2]->successful())->toBeTrue()
        ;
    });

    test('fetches 10 posts concurrently', function () {
        for ($i = 1; $i <= 10; $i++) {
            Http::mock('GET')
                ->url("https://jsonplaceholder.typicode.com/posts/{$i}")
                ->respondJson([
                    'userId' => 1,
                    'id' => $i,
                    'title' => "Post {$i}",
                    'body' => "Body {$i}",
                ])
                ->register()
            ;
        }

        $promises = [];
        for ($i = 1; $i <= 10; $i++) {
            $promises[] = Http::get("https://jsonplaceholder.typicode.com/posts/{$i}");
        }

        $start = microtime(true);
        $results = Promise::all($promises)->await();
        $duration = microtime(true) - $start;

        expect($results)->toHaveCount(10)
            ->and($duration)->toBeLessThan(2.0) // Realistic timing with random delays
        ;

        foreach ($results as $index => $response) {
            expect($response->successful())->toBeTrue()
                ->and($response->json('id'))->toBe($index + 1)
            ;
        }
    });
});

describe('Promise::allSettled() with Mocks using Http Facade', function () {
    test('handles mix of successful and failed requests', function () {
        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/1')
            ->respondJson(['id' => 1, 'title' => 'Post 1'])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/99999')
            ->respondWithStatus(404)
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/3')
            ->respondJson(['id' => 3, 'title' => 'Post 3'])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://invalid-domain-that-does-not-exist-12345.com/test')
            ->fail('Could not resolve host')
            ->register()
        ;

        $promises = [
            Http::get('https://jsonplaceholder.typicode.com/posts/1'),
            Http::get('https://jsonplaceholder.typicode.com/posts/99999'),
            Http::get('https://jsonplaceholder.typicode.com/posts/3'),
            Http::get('https://invalid-domain-that-does-not-exist-12345.com/test'),
        ];

        $results = Promise::allSettled($promises)->await();

        expect($results)->toBeArray()
            ->and($results)->toHaveCount(4)
        ;

        // First request - successful
        expect($results[0])->toHaveKey('status', 'fulfilled')
            ->and($results[0])->toHaveKey('value')
            ->and($results[0]['value']->successful())->toBeTrue()
        ;

        // Second request - 404 but fulfilled
        expect($results[1])->toHaveKey('status', 'fulfilled')
            ->and($results[1]['value']->status())->toBe(404)
        ;

        // Third request - successful
        expect($results[2])->toHaveKey('status', 'fulfilled')
            ->and($results[2]['value']->successful())->toBeTrue()
        ;

        // Fourth request - rejected due to network error
        expect($results[3])->toHaveKey('status', 'rejected')
            ->and($results[3])->toHaveKey('reason')
        ;
    });

    test('all requests succeed with allSettled', function () {
        for ($i = 1; $i <= 3; $i++) {
            Http::mock('GET')
                ->url("https://jsonplaceholder.typicode.com/users/{$i}")
                ->respondJson([
                    'id' => $i,
                    'name' => "User {$i}",
                    'email' => "user{$i}@example.com",
                ])
                ->register()
            ;
        }

        $promises = [
            Http::get('https://jsonplaceholder.typicode.com/users/1'),
            Http::get('https://jsonplaceholder.typicode.com/users/2'),
            Http::get('https://jsonplaceholder.typicode.com/users/3'),
        ];

        $results = Promise::allSettled($promises)->await();

        expect($results)->toHaveCount(3);

        foreach ($results as $result) {
            expect($result)->toHaveKey('status', 'fulfilled')
                ->and($result['value']->successful())->toBeTrue()
            ;
        }
    });
});

describe('Promise::race() with Mocks using Http Facade', function () {
    test('returns the fastest response', function () {
        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/1')
            ->respondJson(['id' => 1, 'title' => 'Post 1'])
            ->delay(0.3)
            ->persistent()
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/2')
            ->respondJson(['id' => 2, 'title' => 'Post 2'])
            ->delay(0.1) // Fastest
            ->persistent()
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/3')
            ->respondJson(['id' => 3, 'title' => 'Post 3'])
            ->delay(0.5)
            ->persistent()
            ->register()
        ;

        $promises = [
            Http::get('https://jsonplaceholder.typicode.com/posts/1'),
            Http::get('https://jsonplaceholder.typicode.com/posts/2'),
            Http::get('https://jsonplaceholder.typicode.com/posts/3'),
        ];

        $result = Promise::race($promises)->await();

        expect($result->successful())->toBeTrue()
            ->and($result->json())->toHaveKey('id')
            ->and($result->json('id'))->toBe(2) // Post 2 is fastest
        ;
    });

    test('race with delayed requests', function () {
        Http::mock('GET')
            ->url('https://api.example.com/slow')
            ->delay(2.0)
            ->respondJson(['speed' => 'slow'])
            ->persistent()
            ->register()
        ;

        Http::mock('GET')
            ->url('https://api.example.com/fast')
            ->delay(0.1)
            ->respondJson(['speed' => 'fast'])
            ->persistent()
            ->register()
        ;

        Http::mock('GET')
            ->url('https://api.example.com/medium')
            ->delay(1.0)
            ->respondJson(['speed' => 'medium'])
            ->persistent()
            ->register()
        ;

        $promises = [
            Http::get('https://api.example.com/slow'),
            Http::get('https://api.example.com/fast'),
            Http::get('https://api.example.com/medium'),
        ];

        $result = Promise::race($promises)->await();

        expect($result->json('speed'))->toBe('fast');
    });
});

describe('Promise::any() with Mocks using Http Facade', function () {
    test('returns first successful response', function () {
        for ($i = 1; $i <= 3; $i++) {
            Http::mock('GET')
                ->url("https://jsonplaceholder.typicode.com/posts/{$i}")
                ->respondJson(['id' => $i, 'title' => "Post {$i}"])
                ->register()
            ;
        }

        $promises = [
            Http::get('https://jsonplaceholder.typicode.com/posts/1'),
            Http::get('https://jsonplaceholder.typicode.com/posts/2'),
            Http::get('https://jsonplaceholder.typicode.com/posts/3'),
        ];

        $result = Promise::any($promises)->await();

        expect($result->successful())->toBeTrue()
            ->and($result->json())->toHaveKey('id')
        ;
    });

    test('succeeds even when some requests fail', function () {
        Http::mock('GET')
            ->url('https://invalid-domain-12345.com/fail')
            ->fail('Could not resolve host')
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/1')
            ->respondJson(['id' => 1, 'title' => 'Post 1'])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://another-invalid-domain-67890.com/fail')
            ->fail('Connection failed')
            ->register()
        ;

        $promises = [
            Http::get('https://invalid-domain-12345.com/fail'),
            Http::get('https://jsonplaceholder.typicode.com/posts/1'),
            Http::get('https://another-invalid-domain-67890.com/fail'),
        ];

        $result = Promise::any($promises)->await();

        expect($result->successful())->toBeTrue()
            ->and($result->json('id'))->toBe(1)
        ;
    });
});

describe('Promise::concurrent() with Mocks using Http Facade', function () {
    test('processes tasks with concurrency limit', function () {
        for ($i = 1; $i <= 20; $i++) {
            Http::mock('GET')
                ->url("https://jsonplaceholder.typicode.com/posts/{$i}")
                ->respondJson([
                    'userId' => 1,
                    'id' => $i,
                    'title' => "Post {$i}",
                    'body' => "Body {$i}",
                ])
                ->register()
            ;
        }

        $tasks = [];
        for ($i = 1; $i <= 20; $i++) {
            $tasks[] = fn () => Http::get("https://jsonplaceholder.typicode.com/posts/{$i}");
        }

        $start = microtime(true);
        $results = Promise::concurrent($tasks, 5)->await();
        $duration = microtime(true) - $start;

        expect($results)->toHaveCount(20);

        foreach ($results as $index => $response) {
            expect($response->successful())->toBeTrue()
                ->and($response->json('id'))->toBe($index + 1)
            ;
        }
    });

    test('concurrent with different resources', function () {
        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/1')
            ->respondJson(['id' => 1, 'title' => 'Post 1'])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/users/1')
            ->respondJson(['id' => 1, 'name' => 'User 1'])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/comments/1')
            ->respondJson(['id' => 1, 'body' => 'Comment 1'])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/albums/1')
            ->respondJson(['id' => 1, 'title' => 'Album 1'])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/photos/1')
            ->respondJson(['id' => 1, 'title' => 'Photo 1'])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/todos/1')
            ->respondJson(['id' => 1, 'title' => 'Todo 1'])
            ->register()
        ;

        $tasks = [
            fn () => Http::get('https://jsonplaceholder.typicode.com/posts/1'),
            fn () => Http::get('https://jsonplaceholder.typicode.com/users/1'),
            fn () => Http::get('https://jsonplaceholder.typicode.com/comments/1'),
            fn () => Http::get('https://jsonplaceholder.typicode.com/albums/1'),
            fn () => Http::get('https://jsonplaceholder.typicode.com/photos/1'),
            fn () => Http::get('https://jsonplaceholder.typicode.com/todos/1'),
        ];

        $results = Promise::concurrent($tasks, 3)->await();

        expect($results)->toHaveCount(6);

        foreach ($results as $response) {
            expect($response->successful())->toBeTrue();
        }
    });

    test('concurrent with POST requests', function () {
        for ($i = 1; $i <= 5; $i++) {
            Http::mock('POST')
                ->url('https://jsonplaceholder.typicode.com/posts')
                ->respondWithStatus(201)
                ->respondJson([
                    'id' => 101,
                    'title' => "Post {$i}",
                    'body' => "Body content {$i}",
                    'userId' => 1,
                ])
                ->register()
            ;
        }

        $tasks = [];
        for ($i = 1; $i <= 5; $i++) {
            $tasks[] = fn () => Http::post('https://jsonplaceholder.typicode.com/posts', [
                'title' => "Post {$i}",
                'body' => "Body content {$i}",
                'userId' => 1,
            ]);
        }

        $results = Promise::concurrent($tasks, 2)->await();

        expect($results)->toHaveCount(5);

        foreach ($results as $response) {
            expect($response->status())->toBe(201);
        }
    });
});

describe('Promise::batch() with Mocks using Http Facade', function () {
    test('processes tasks in batches', function () {
        for ($i = 1; $i <= 15; $i++) {
            Http::mock('GET')
                ->url("https://jsonplaceholder.typicode.com/posts/{$i}")
                ->respondJson([
                    'userId' => 1,
                    'id' => $i,
                    'title' => "Post {$i}",
                    'body' => "Body {$i}",
                ])
                ->register()
            ;
        }

        $tasks = [];
        for ($i = 1; $i <= 15; $i++) {
            $tasks[] = fn () => Http::get("https://jsonplaceholder.typicode.com/posts/{$i}");
        }

        $results = Promise::batch($tasks, 5, 3)->await();

        expect($results)->toHaveCount(15);

        foreach ($results as $index => $response) {
            expect($response->successful())->toBeTrue()
                ->and($response->json('id'))->toBe($index + 1)
            ;
        }
    });

    test('batch with mixed request types', function () {
        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/1')
            ->respondJson(['id' => 1, 'title' => 'Post 1'])
            ->register()
        ;

        Http::mock('POST')
            ->url('https://jsonplaceholder.typicode.com/posts')
            ->respondWithStatus(201)
            ->respondJson(['id' => 101, 'title' => 'Test'])
            ->register()
        ;

        Http::mock('PUT')
            ->url('https://jsonplaceholder.typicode.com/posts/1')
            ->respondJson(['id' => 1, 'title' => 'Updated'])
            ->register()
        ;

        Http::mock('DELETE')
            ->url('https://jsonplaceholder.typicode.com/posts/1')
            ->respondJson([])
            ->register()
        ;

        Http::mock('PATCH')
            ->url('https://jsonplaceholder.typicode.com/posts/1')
            ->respondJson(['id' => 1, 'title' => 'Patched'])
            ->register()
        ;

        $tasks = [
            fn () => Http::get('https://jsonplaceholder.typicode.com/posts/1'),
            fn () => Http::post('https://jsonplaceholder.typicode.com/posts', ['title' => 'Test']),
            fn () => Http::put('https://jsonplaceholder.typicode.com/posts/1', ['title' => 'Updated']),
            fn () => Http::delete('https://jsonplaceholder.typicode.com/posts/1'),
            fn () => Http::patch('https://jsonplaceholder.typicode.com/posts/1', ['title' => 'Patched']),
        ];

        $results = Promise::batch($tasks, 2, 2)->await();

        expect($results)->toHaveCount(5);

        expect($results[0]->status())->toBe(200)
            ->and($results[1]->status())->toBe(201)
            ->and($results[2]->status())->toBe(200)
            ->and($results[3]->status())->toBe(200)
            ->and($results[4]->status())->toBe(200)
        ;
    });
});

describe('Promise::concurrentSettled() with Mocks using Http Facade', function () {

    beforeEach(function () {
        Http::startTesting()->withGlobalRandomDelay(0.1, 0.3);
    });

    afterEach(function () {
        Http::stopTesting();
    });

    test('processes all tasks even if some fail', function () {
        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/1')
            ->respondJson(['id' => 1, 'title' => 'Post 1'])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/99999')
            ->respondWithStatus(404)
            ->register()
        ;

        Http::mock('GET')
            ->url('https://invalid-domain-test-12345.com/fail')
            ->fail('Connection failed')
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/3')
            ->respondJson(['id' => 3, 'title' => 'Post 3'])
            ->register()
        ;

        $tasks = [
            fn () => Http::get('https://jsonplaceholder.typicode.com/posts/1'),
            fn () => Http::get('https://jsonplaceholder.typicode.com/posts/99999'),
            fn () => Http::get('https://invalid-domain-test-12345.com/fail'),
            fn () => Http::get('https://jsonplaceholder.typicode.com/posts/3'),
        ];

        $results = Promise::concurrentSettled($tasks, 2)->await();

        expect($results)->toHaveCount(4);

        // First - fulfilled
        expect($results[0])->toHaveKey('status', 'fulfilled')
            ->and($results[0]['value']->successful())->toBeTrue()
        ;

        // Second - fulfilled but 404
        expect($results[1])->toHaveKey('status', 'fulfilled')
            ->and($results[1]['value']->status())->toBe(404)
        ;

        // Third - rejected
        expect($results[2])->toHaveKey('status', 'rejected');

        // Fourth - fulfilled
        expect($results[3])->toHaveKey('status', 'fulfilled')
            ->and($results[3]['value']->successful())->toBeTrue()
        ;
    });

    test('all tasks succeed with concurrentSettled', function () {
        for ($i = 1; $i <= 10; $i++) {
            Http::mock('GET')
                ->url("https://jsonplaceholder.typicode.com/users/{$i}")
                ->respondJson([
                    'id' => $i,
                    'name' => "User {$i}",
                    'email' => "user{$i}@example.com",
                ])
                ->register()
            ;
        }

        $tasks = [];
        for ($i = 1; $i <= 10; $i++) {
            $tasks[] = fn () => Http::get("https://jsonplaceholder.typicode.com/users/{$i}");
        }

        $results = Promise::concurrentSettled($tasks, 5)->await();

        expect($results)->toHaveCount(10);

        foreach ($results as $result) {
            expect($result)->toHaveKey('status', 'fulfilled')
                ->and($result['value']->successful())->toBeTrue()
            ;
        }
    });
});

describe('Promise::batchSettled() with Mocks using Http Facade', function () {

    beforeEach(function () {
        Http::startTesting()->withGlobalRandomDelay(0.1, 0.3);
    });

    afterEach(function () {
        Http::stopTesting();
    });

    test('processes batches and handles failures gracefully', function () {
        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/1')
            ->respondJson(['id' => 1, 'title' => 'Post 1'])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/2')
            ->respondJson(['id' => 2, 'title' => 'Post 2'])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://invalid-test-domain-99999.com/fail')
            ->fail('Connection failed')
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/3')
            ->respondJson(['id' => 3, 'title' => 'Post 3'])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/99999')
            ->respondWithStatus(404)
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/4')
            ->respondJson(['id' => 4, 'title' => 'Post 4'])
            ->register()
        ;

        $tasks = [
            fn () => Http::get('https://jsonplaceholder.typicode.com/posts/1'),
            fn () => Http::get('https://jsonplaceholder.typicode.com/posts/2'),
            fn () => Http::get('https://invalid-test-domain-99999.com/fail'),
            fn () => Http::get('https://jsonplaceholder.typicode.com/posts/3'),
            fn () => Http::get('https://jsonplaceholder.typicode.com/posts/99999'),
            fn () => Http::get('https://jsonplaceholder.typicode.com/posts/4'),
        ];

        $results = Promise::batchSettled($tasks, 3, 2)->await();

        expect($results)->toHaveCount(6);

        $successCount = 0;
        $rejectedCount = 0;

        foreach ($results as $result) {
            if ($result['status'] === 'fulfilled') {
                $successCount++;
            } else {
                $rejectedCount++;
            }
        }

        expect($successCount)->toBeGreaterThan(0)
            ->and($rejectedCount)->toBeGreaterThan(0)
        ;
    });
});

describe('Concurrent Requests with Mocks using Http Facade', function () {

    beforeEach(function () {
        Http::startTesting()->allowPassthrough()->withGlobalRandomDelay(0.1, 0.3);
    });

    afterEach(function () {
        Http::stopTesting();
    });

    test('combines real and mocked requests with Promise::all()', function () {
        Http::mock('GET')
            ->url('https://api.example.com/mocked')
            ->respondJson(['source' => 'mock', 'id' => 999])
            ->register()
        ;

        // Real API mock
        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/1')
            ->respondJson(['id' => 1, 'title' => 'Real Post', 'body' => 'Real body', 'userId' => 1])
            ->register()
        ;

        $promises = [
            'real' => Http::get('https://jsonplaceholder.typicode.com/posts/1'),
            'mock' => Http::get('https://api.example.com/mocked'),
        ];

        $results = Promise::all($promises)->await();

        expect($results)->toHaveCount(2)
            ->and($results['real']->json('id'))->toBe(1)
            ->and($results['mock']->json('source'))->toBe('mock')
            ->and($results['mock']->json('id'))->toBe(999)
        ;
    });

    test('concurrent with retry simulation', function () {
        Http::mock('GET')
            ->url('https://api.example.com/retry-test')
            ->failUntilAttempt(3)
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/1')
            ->respondJson(['id' => 1, 'title' => 'Post 1'])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/2')
            ->respondJson(['id' => 2, 'title' => 'Post 2'])
            ->register()
        ;

        $tasks = [
            fn () => Http::retry(5, 0.01)->get('https://api.example.com/retry-test'),
            fn () => Http::get('https://jsonplaceholder.typicode.com/posts/1'),
            fn () => Http::get('https://jsonplaceholder.typicode.com/posts/2'),
        ];

        $results = Promise::concurrent($tasks, 2)->await();

        expect($results)->toHaveCount(3)
            ->and($results[0]->successful())->toBeTrue()
            ->and($results[1]->successful())->toBeTrue()
            ->and($results[2]->successful())->toBeTrue()
        ;
    });

    test('batch with rate limiting simulation', function () {
        for ($i = 1; $i <= 5; $i++) {
            Http::mock('GET')
                ->url("https://api.example.com/rate-limited/{$i}")
                ->rateLimitedUntilAttempt(2)
                ->register()
            ;
        }

        $tasks = [];
        for ($i = 1; $i <= 5; $i++) {
            $tasks[] = fn () => Http::retry(5, 0.01)
                ->get("https://api.example.com/rate-limited/{$i}")
            ;
        }

        $results = Promise::batch($tasks, 2, 2)->await();

        expect($results)->toHaveCount(5);

        foreach ($results as $response) {
            expect($response->successful())->toBeTrue();
        }
    });

    test('allSettled with mixed mock scenarios', function () {
        Http::mock('GET')
            ->url('https://api.example.com/success')
            ->respondJson(['status' => 'ok'])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://api.example.com/fail')
            ->fail('Connection failed')
            ->register()
        ;

        Http::mock('GET')
            ->url('https://api.example.com/slow')
            ->delay(0.5)
            ->respondJson(['status' => 'slow'])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/1')
            ->respondJson(['id' => 1, 'title' => 'Post 1'])
            ->register()
        ;

        $promises = [
            Http::get('https://api.example.com/success'),
            Http::get('https://api.example.com/fail'),
            Http::get('https://api.example.com/slow'),
            Http::get('https://jsonplaceholder.typicode.com/posts/1'),
        ];

        $results = Promise::allSettled($promises)->await();

        expect($results)->toHaveCount(4)
            ->and($results[0]['status'])->toBe('fulfilled')
            ->and($results[1]['status'])->toBe('rejected')
            ->and($results[2]['status'])->toBe('fulfilled')
            ->and($results[3]['status'])->toBe('fulfilled')
        ;
    });
});

describe('Concurrency Performance Tests with Mocks using Http Facade', function () {

    beforeEach(function () {
        Http::startTesting()->withGlobalRandomDelay(0.1, 0.3);
    });

    afterEach(function () {
        Http::stopTesting();
    });

    test('concurrent requests are faster than sequential', function () {
        for ($i = 1; $i <= 5; $i++) {
            Http::mock('GET')
                ->url("https://jsonplaceholder.typicode.com/posts/{$i}")
                ->respondJson([
                    'userId' => 1,
                    'id' => $i,
                    'title' => "Post {$i}",
                    'body' => "Body {$i}",
                ])
                ->persistent()
                ->register()
            ;
        }

        // Sequential execution
        $sequentialStart = microtime(true);
        for ($i = 1; $i <= 5; $i++) {
            Http::get("https://jsonplaceholder.typicode.com/posts/{$i}")->await();
        }
        $sequentialDuration = microtime(true) - $sequentialStart;

        // Concurrent execution
        $promises = [];
        for ($i = 1; $i <= 5; $i++) {
            $promises[] = Http::get("https://jsonplaceholder.typicode.com/posts/{$i}");
        }

        $concurrentStart = microtime(true);
        Promise::all($promises)->await();
        $concurrentDuration = microtime(true) - $concurrentStart;

        expect($concurrentDuration)->toBeLessThan($sequentialDuration);
    });

    test('batch processing with timing', function () {
        for ($i = 1; $i <= 20; $i++) {
            Http::mock('GET')
                ->url("https://jsonplaceholder.typicode.com/posts/{$i}")
                ->respondJson([
                    'userId' => 1,
                    'id' => $i,
                    'title' => "Post {$i}",
                    'body' => "Body {$i}",
                ])
                ->register()
            ;
        }

        $tasks = [];
        for ($i = 1; $i <= 20; $i++) {
            $tasks[] = fn () => Http::get("https://jsonplaceholder.typicode.com/posts/{$i}");
        }

        $start = microtime(true);
        $results = Promise::batch($tasks, 5, 5)->await();
        $duration = microtime(true) - $start;

        expect($results)->toHaveCount(20)
            ->and($duration)->toBeLessThan(5.0) // More realistic timeout with random delays
        ;
    });
});

describe('Complex Concurrent Workflows with Mocks using Http Facade', function () {
    test('fetch user then fetch their posts and todos concurrently', function () {
        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/users/1')
            ->respondJson([
                'id' => 1,
                'name' => 'John Doe',
                'email' => 'john@example.com',
            ])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts?userId=1')
            ->respondJson([
                ['id' => 1, 'userId' => 1, 'title' => 'Post 1'],
                ['id' => 2, 'userId' => 1, 'title' => 'Post 2'],
            ])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/todos?userId=1')
            ->respondJson([
                ['id' => 1, 'userId' => 1, 'title' => 'Todo 1', 'completed' => false],
                ['id' => 2, 'userId' => 1, 'title' => 'Todo 2', 'completed' => true],
            ])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/albums?userId=1')
            ->respondJson([
                ['id' => 1, 'userId' => 1, 'title' => 'Album 1'],
                ['id' => 2, 'userId' => 1, 'title' => 'Album 2'],
            ])
            ->register()
        ;

        $userResponse = Http::get('https://jsonplaceholder.typicode.com/users/1')->await();
        $userId = $userResponse->json('id');

        $promises = [
            'posts' => Http::get("https://jsonplaceholder.typicode.com/posts?userId={$userId}"),
            'todos' => Http::get("https://jsonplaceholder.typicode.com/todos?userId={$userId}"),
            'albums' => Http::get("https://jsonplaceholder.typicode.com/albums?userId={$userId}"),
        ];

        $results = Promise::all($promises)->await();

        expect($results)->toHaveCount(3)
            ->and($results['posts']->json())->toBeArray()
            ->and($results['todos']->json())->toBeArray()
            ->and($results['albums']->json())->toBeArray()
        ;
    });

    test('parallel POST requests with Promise::all()', function () {
        $postsToCreate = [
            ['title' => 'First Post', 'body' => 'Content 1', 'userId' => 1],
            ['title' => 'Second Post', 'body' => 'Content 2', 'userId' => 1],
            ['title' => 'Third Post', 'body' => 'Content 3', 'userId' => 1],
        ];

        foreach ($postsToCreate as $index => $post) {
            Http::mock('POST')
                ->url('https://jsonplaceholder.typicode.com/posts')
                ->expectJson($post)
                ->respondWithStatus(201)
                ->respondJson([
                    'id' => 101 + $index,
                    'title' => $post['title'],
                    'body' => $post['body'],
                    'userId' => $post['userId'],
                ])
                ->register()
            ;
        }

        $promises = [];
        foreach ($postsToCreate as $post) {
            $promises[] = Http::post('https://jsonplaceholder.typicode.com/posts', $post);
        }

        $results = Promise::all($promises)->await();

        expect($results)->toHaveCount(3);

        foreach ($results as $index => $response) {
            expect($response->status())->toBe(201)
                ->and($response->json('title'))->toBe($postsToCreate[$index]['title'])
            ;
        }
    });

    test('race between multiple API endpoints', function () {
        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/posts/1')
            ->respondJson(['id' => 1, 'title' => 'Post 1'])
            ->delay(0.3)
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/users/1')
            ->respondJson(['id' => 1, 'name' => 'User 1'])
            ->delay(0.1) // Fastest
            ->register()
        ;

        Http::mock('GET')
            ->url('https://jsonplaceholder.typicode.com/comments/1')
            ->respondJson(['id' => 1, 'body' => 'Comment 1'])
            ->delay(0.5)
            ->register()
        ;

        $promises = [
            Http::get('https://jsonplaceholder.typicode.com/posts/1'),
            Http::get('https://jsonplaceholder.typicode.com/users/1'),
            Http::get('https://jsonplaceholder.typicode.com/comments/1'),
        ];

        $winner = Promise::race($promises)->await();

        expect($winner->successful())->toBeTrue()
            ->and($winner->json())->toHaveKey('id')
            ->and($winner->json())->toHaveKey('name') // User endpoint wins
        ;
    });
});

describe('Error Handling with Concurrent Requests', function () {
    test('handles partial failures in concurrent requests', function () {
        Http::mock('GET')
            ->url('https://api.example.com/endpoint1')
            ->respondJson(['id' => 1, 'status' => 'success'])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://api.example.com/endpoint2')
            ->fail('Network timeout')
            ->register()
        ;

        Http::mock('GET')
            ->url('https://api.example.com/endpoint3')
            ->respondWithStatus(500)
            ->respondJson(['error' => 'Internal server error'])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://api.example.com/endpoint4')
            ->respondJson(['id' => 4, 'status' => 'success'])
            ->register()
        ;

        $promises = [
            Http::get('https://api.example.com/endpoint1'),
            Http::get('https://api.example.com/endpoint2'),
            Http::get('https://api.example.com/endpoint3'),
            Http::get('https://api.example.com/endpoint4'),
        ];

        $results = Promise::allSettled($promises)->await();

        expect($results)->toHaveCount(4);

        $fulfilled = array_filter($results, fn ($r) => $r['status'] === 'fulfilled');
        $rejected = array_filter($results, fn ($r) => $r['status'] === 'rejected');

        expect(count($fulfilled))->toBe(3) // endpoints 1, 3, 4 (3 is HTTP error but fulfilled)
            ->and(count($rejected))->toBe(1) // endpoint 2 (network error)
        ;
    });

    test('retry logic works with concurrent requests', function () {
        // First endpoint: fails twice then succeeds
        Http::mock('GET')
            ->url('https://api.example.com/retry1')
            ->failUntilAttempt(3, 'Temporary failure')
            ->register()
        ;

        // Second endpoint: succeeds immediately
        Http::mock('GET')
            ->url('https://api.example.com/retry2')
            ->respondJson(['id' => 2, 'status' => 'immediate success'])
            ->register()
        ;

        // Third endpoint: rate limited then succeeds
        Http::mock('GET')
            ->url('https://api.example.com/retry3')
            ->rateLimitedUntilAttempt(2)
            ->register()
        ;

        $tasks = [
            fn () => Http::retry(5, 0.01)->get('https://api.example.com/retry1'),
            fn () => Http::get('https://api.example.com/retry2'),
            fn () => Http::retry(5, 0.01)->get('https://api.example.com/retry3'),
        ];

        $results = Promise::concurrent($tasks, 3)->await();

        expect($results)->toHaveCount(3);

        foreach ($results as $response) {
            expect($response->successful())->toBeTrue();
        }
    });

    test('timeout handling in concurrent requests', function () {
        Http::mock('GET')
            ->url('https://api.example.com/fast')
            ->respondJson(['speed' => 'fast'])
            ->delay(0.1)
            ->register()
        ;

        Http::mock('GET')
            ->url('https://api.example.com/slow')
            ->timeout(5.0)
            ->register()
        ;

        Http::mock('GET')
            ->url('https://api.example.com/normal')
            ->respondJson(['speed' => 'normal'])
            ->delay(0.2)
            ->register()
        ;

        $promises = [
            'fast' => Http::timeout(10)->get('https://api.example.com/fast'),
            'slow' => Http::timeout(10)->get('https://api.example.com/slow'),
            'normal' => Http::timeout(10)->get('https://api.example.com/normal'),
        ];

        $results = Promise::allSettled($promises)->await();

        expect($results)->toHaveCount(3);
        expect($results['fast']['status'])->toBe('fulfilled');
        expect($results['slow']['status'])->toBe('rejected'); // Timeout
        expect($results['normal']['status'])->toBe('fulfilled');
    });
});

describe('Advanced Promise Patterns with Mocks', function () {

    beforeEach(function () {
        Http::startTesting()->withGlobalRandomDelay(0.1, 0.3);
    });

    afterEach(function () {
        Http::stopTesting();
    });

    test('cascading requests with Promise chaining', function () {
        Http::mock('GET')
            ->url('https://api.example.com/user/1')
            ->respondJson(['id' => 1, 'name' => 'John', 'companyId' => 5])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://api.example.com/company/5')
            ->respondJson(['id' => 5, 'name' => 'Acme Corp', 'location' => 'New York'])
            ->register()
        ;

        Http::mock('GET')
            ->url('https://api.example.com/company/5/employees')
            ->respondJson([
                ['id' => 1, 'name' => 'John'],
                ['id' => 2, 'name' => 'Jane'],
                ['id' => 3, 'name' => 'Bob'],
            ])
            ->register()
        ;

        $user = Http::get('https://api.example.com/user/1')->await();
        $companyId = $user->json('companyId');

        $promises = [
            'company' => Http::get("https://api.example.com/company/{$companyId}"),
            'employees' => Http::get("https://api.example.com/company/{$companyId}/employees"),
        ];

        $results = Promise::all($promises)->await();

        expect($results['company']->json('name'))->toBe('Acme Corp')
            ->and($results['employees']->json())->toHaveCount(3)
        ;
    });

    test('load balancing simulation with race', function () {
        // Simulate multiple servers with different response times
        Http::mock('GET')
            ->url('https://server1.example.com/data')
            ->respondJson(['server' => 'server1', 'data' => 'result'])
            ->randomDelay(0.2, 0.4)
            ->register()
        ;

        Http::mock('GET')
            ->url('https://server2.example.com/data')
            ->respondJson(['server' => 'server2', 'data' => 'result'])
            ->randomDelay(0.1, 0.3)
            ->register()
        ;

        Http::mock('GET')
            ->url('https://server3.example.com/data')
            ->respondJson(['server' => 'server3', 'data' => 'result'])
            ->randomDelay(0.15, 0.35)
            ->register()
        ;

        $promises = [
            Http::get('https://server1.example.com/data'),
            Http::get('https://server2.example.com/data'),
            Http::get('https://server3.example.com/data'),
        ];

        $fastest = Promise::race($promises)->await();

        expect($fastest->successful())->toBeTrue()
            ->and($fastest->json('data'))->toBe('result')
            ->and($fastest->json('server'))->toBeIn(['server1', 'server2', 'server3'])
        ;
    });

    test('fan-out fan-in pattern', function () {
        // Mock primary data source
        Http::mock('GET')
            ->url('https://api.example.com/items')
            ->respondJson([
                ['id' => 1, 'name' => 'Item 1'],
                ['id' => 2, 'name' => 'Item 2'],
                ['id' => 3, 'name' => 'Item 3'],
            ])
            ->register()
        ;

        // Mock detail endpoints for each item
        for ($i = 1; $i <= 3; $i++) {
            Http::mock('GET')
                ->url("https://api.example.com/items/{$i}/details")
                ->respondJson([
                    'id' => $i,
                    'description' => "Detailed info for item {$i}",
                    'price' => 100 * $i,
                ])
                ->register();
        }

        // Fan-out: get list of items
        $items = Http::get('https://api.example.com/items')->await();
        $itemIds = array_column($items->json(), 'id');

        // Fan-out: fetch details for all items concurrently
        $detailPromises = [];
        foreach ($itemIds as $id) {
            $detailPromises[$id] = Http::get("https://api.example.com/items/{$id}/details");
        }

        // Fan-in: collect all results
        $details = Promise::all($detailPromises)->await();

        expect($details)->toHaveCount(3);
        foreach ($details as $id => $detail) {
            expect($detail->json('id'))->toBe($id)
                ->and($detail->json('price'))->toBe(100 * $id);
        }
    });
});
