<?php

declare(strict_types=1);

use Hibla\HttpClient\Stream;
use Hibla\HttpClient\StreamingResponse;

describe('StreamingResponse', function () {
    it('creates a streaming response', function () {
        $stream = Stream::fromString('response body');
        $response = new StreamingResponse($stream, 200, ['Content-Type' => 'text/plain']);

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getHeaderLine('Content-Type'))->toBe('text/plain')
        ;
    });

    it('gets response body as string', function () {
        $content = 'Hello, World!';
        $stream = Stream::fromString($content);
        $response = new StreamingResponse($stream, 200);

        expect($response->body())->toBe($content);
    });

    it('caches body after first read', function () {
        $stream = Stream::fromString('test content');
        $response = new StreamingResponse($stream, 200);

        $first = $response->body();
        $second = $response->body();

        expect($first)->toBe($second)
            ->and($first)->toBe('test content')
        ;
    });

    it('parses JSON response', function () {
        $data = ['name' => 'John', 'age' => 30, 'active' => true];
        $stream = Stream::fromString(json_encode($data));
        $response = new StreamingResponse($stream, 200);

        expect($response->json())->toBe($data);
    });

    it('returns null for invalid JSON when no default provided', function () {
        $stream = Stream::fromString('invalid json');
        $response = new StreamingResponse($stream, 200);

        expect($response->json())->toBeNull();
    });

    it('returns default value for invalid JSON', function () {
        $stream = Stream::fromString('invalid json');
        $response = new StreamingResponse($stream, 200);

        expect($response->json(null, []))->toBe([])
            ->and($response->json(null, 'error'))->toBe('error')
            ->and($response->json(null, 0))->toBe(0)
        ;
    });

    it('returns null for non-array JSON when no default provided', function () {
        $stream = Stream::fromString('"just a string"');
        $response = new StreamingResponse($stream, 200);

        expect($response->json())->toBeNull();
    });

    it('returns default value for non-array JSON', function () {
        $stream = Stream::fromString('"just a string"');
        $response = new StreamingResponse($stream, 200);

        expect($response->json(null, []))->toBe([]);
    });

    it('handles empty stream', function () {
        $stream = Stream::fromString('');
        $response = new StreamingResponse($stream, 200);

        expect($response->body())->toBe('')
            ->and($response->json())->toBeNull()
            ->and($response->json(null, []))->toBe([])
        ;
    });

    it('preserves other response properties', function () {
        $stream = Stream::fromString('body');
        $headers = [
            'Content-Type' => 'application/json',
            'X-Custom-Header' => 'custom-value',
        ];
        $response = new StreamingResponse($stream, 201, $headers);

        expect($response->getStatusCode())->toBe(201)
            ->and($response->getHeaderLine('Content-Type'))->toBe('application/json')
            ->and($response->getHeaderLine('X-Custom-Header'))->toBe('custom-value')
        ;
    });

    it('supports dot notation for nested JSON access', function () {
        $data = ['user' => ['name' => 'John', 'email' => 'john@example.com']];
        $stream = Stream::fromString(json_encode($data));
        $response = new StreamingResponse($stream, 200);

        expect($response->json('user.name'))->toBe('John')
            ->and($response->json('user.email'))->toBe('john@example.com')
        ;
    });

    it('returns default for missing nested keys', function () {
        $data = ['user' => ['name' => 'John']];
        $stream = Stream::fromString(json_encode($data));
        $response = new StreamingResponse($stream, 200);

        expect($response->json('user.email', 'no-email@example.com'))->toBe('no-email@example.com')
            ->and($response->json('missing.key', 'default'))->toBe('default')
        ;
    });

    it('handles deeply nested JSON with dot notation', function () {
        $data = [
            'data' => [
                'user' => [
                    'profile' => [
                        'address' => [
                            'city' => 'New York',
                        ],
                    ],
                ],
            ],
        ];
        $stream = Stream::fromString(json_encode($data));
        $response = new StreamingResponse($stream, 200);

        expect($response->json('data.user.profile.address.city'))->toBe('New York');
    });
});
