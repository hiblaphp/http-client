<?php

declare(strict_types=1);

use Hibla\HttpClient\Response;
use Hibla\HttpClient\StreamingResponse;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\FileManager;
use Hibla\HttpClient\ValueObjects\RetryConfig;

test('executes request with retry on first attempt success', function () {
    $fileManager = new FileManager();
    $executor = createStandardRequestExecutor($fileManager);
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/data');
    $mock->setBody('{"success": true}');
    $mocks[] = $mock;

    $retryConfig = new RetryConfig(maxRetries: 3);

    $result = $executor->execute(
        'https://api.example.com/data',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        [],
        $retryConfig
    )->wait();

    expect($result)->toBeInstanceOf(Response::class)
        ->and($result->body())->toBe('{"success": true}')
        ->and($mocks)->toBeEmpty()
    ;
});

test('retries failed request until success', function () {
    $fileManager = new FileManager();
    $executor = createStandardRequestExecutor($fileManager);
    $mocks = [];

    $mock1 = new MockedRequest('GET');
    $mock1->setUrlPattern('https://api.example.com/retry');
    $mock1->setError('Connection timeout');
    $mock1->setRetryable(true);
    $mocks[] = $mock1;

    $mock2 = new MockedRequest('GET');
    $mock2->setUrlPattern('https://api.example.com/retry');
    $mock2->setBody('{"retried": true}');
    $mocks[] = $mock2;

    $retryConfig = new RetryConfig(maxRetries: 3, baseDelay: 0.01);

    $result = $executor->execute(
        'https://api.example.com/retry',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        [],
        $retryConfig
    )->wait();

    expect($result->body())->toBe('{"retried": true}')
        ->and($mocks)->toBeEmpty()
    ;
});

test('exhausts all retry attempts and fails', function () {
    $fileManager = new FileManager();
    $executor = createStandardRequestExecutor($fileManager);
    $mocks = [];

    // maxRetries: 3 means 1 initial attempt + 3 retries = 4 total attempts.
    // We must provide 4 mocks so the provider doesn't crash before the limit is reached.
    for ($i = 0; $i < 4; $i++) {
        $mock = new MockedRequest('GET');
        $mock->setUrlPattern('https://api.example.com/always-fails');
        $mock->setError('Connection refused');
        $mock->setRetryable(true);
        $mocks[] = $mock;
    }

    $retryConfig = new RetryConfig(maxRetries: 3, baseDelay: 0.01);

    $executor->execute(
        'https://api.example.com/always-fails',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        [],
        $retryConfig
    )->wait();
})->throws(Hibla\HttpClient\Exceptions\NetworkException::class);

test('persistent mock is not removed during retries', function () {
    $fileManager = new FileManager();
    $executor = createStandardRequestExecutor($fileManager);
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/persistent');
    $mock->setBody('{"persistent": true}');
    $mock->setPersistent(true);
    $mocks[] = $mock;

    $retryConfig = new RetryConfig(maxRetries: 3);

    $result = $executor->execute(
        'https://api.example.com/persistent',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $mocks,
        [],
        $retryConfig
    )->wait();

    expect($result->body())->toBe('{"persistent": true}')
        ->and($mocks)->toHaveCount(1)
    ;
});

test('retries download on failure', function () {
    $fileManager = new FileManager();
    $executor = createStandardRequestExecutor($fileManager);
    $mocks = [];

    $mock1 = new MockedRequest('GET');
    $mock1->setUrlPattern('https://api.example.com/file.pdf');
    $mock1->setError('Network error');
    $mock1->setRetryable(true);
    $mocks[] = $mock1;

    $mock2 = new MockedRequest('GET');
    $mock2->setUrlPattern('https://api.example.com/file.pdf');
    $mock2->setBody('PDF content');
    $mocks[] = $mock2;

    $retryConfig = new RetryConfig(maxRetries: 3, baseDelay: 0.01);
    $destPath = $fileManager->createTempFile();

    /** @var array $result */
    $result = $executor->execute(
        'https://api.example.com/file.pdf',
        [
            CURLOPT_CUSTOMREQUEST => 'GET',
            'download' => $destPath,
        ],
        $mocks,
        [],
        $retryConfig
    )->wait();

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('file')
        ->and($result)->toHaveKey('status')
        ->and($result['status'])->toBe(200)
        ->and(file_exists($result['file']))->toBeTrue()
        ->and(file_get_contents($result['file']))->toBe('PDF content')
    ;
});

test('retries streaming on failure', function () {
    $fileManager = new FileManager();
    $executor = createStandardRequestExecutor($fileManager);
    $mocks = [];

    $mock1 = new MockedRequest('GET');
    $mock1->setUrlPattern('https://api.example.com/stream');
    $mock1->setError('Connection dropped');
    $mock1->setRetryable(true);
    $mocks[] = $mock1;

    $mock2 = new MockedRequest('GET');
    $mock2->setUrlPattern('https://api.example.com/stream');
    $mock2->setBody('streaming content');
    $mocks[] = $mock2;

    $retryConfig = new RetryConfig(maxRetries: 3, baseDelay: 0.01);
    $chunkReceived = null;

    $result = $executor->execute(
        'https://api.example.com/stream',
        [
            CURLOPT_CUSTOMREQUEST => 'GET',
            'stream' => true,
            'on_chunk' => function ($chunk) use (&$chunkReceived) {
                $chunkReceived = $chunk;
            },
        ],
        $mocks,
        [],
        $retryConfig
    )->wait();

    expect($result)->toBeInstanceOf(StreamingResponse::class)
        ->and($chunkReceived)->toBe('streaming content')
    ;
});

test('records requests during retry attempts', function () {
    $fileManager = new FileManager();
    $executor = createStandardRequestExecutor($fileManager);
    $mocks = [];

    for ($i = 0; $i < 2; $i++) {
        $mock = new MockedRequest('POST');
        $mock->setUrlPattern('https://api.example.com/create');
        $mock->setError('Timeout');
        $mock->setRetryable(true);
        $mocks[] = $mock;
    }

    $mock = new MockedRequest('POST');
    $mock->setUrlPattern('https://api.example.com/create');
    $mock->setStatusCode(201);
    $mock->setBody('{"created": true}');
    $mocks[] = $mock;

    $retryConfig = new RetryConfig(maxRetries: 3, baseDelay: 0.01);

    $result = $executor->execute(
        'https://api.example.com/create',
        [CURLOPT_CUSTOMREQUEST => 'POST'],
        $mocks,
        [],
        $retryConfig
    )->wait();

    expect($result->status())->toBe(201)
        ->and($result->body())->toBe('{"created": true}')
        ->and($mocks)->toBeEmpty()
    ;
});
