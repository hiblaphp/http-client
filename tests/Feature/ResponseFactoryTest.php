<?php

use Hibla\HttpClient\CacheConfig;
use Hibla\HttpClient\Exceptions\HttpException;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\StreamingResponse;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\CacheManager;
use Hibla\HttpClient\Testing\Utilities\Factories\DownloadResponseFactory;
use Hibla\HttpClient\Testing\Utilities\Factories\RetryableResponseFactory;
use Hibla\HttpClient\Testing\Utilities\Factories\StandardResponseFactory;
use Hibla\HttpClient\Testing\Utilities\Factories\StreamingResponseFactory;
use Hibla\HttpClient\Testing\Utilities\Handlers\CacheHandler;
use Hibla\HttpClient\Testing\Utilities\Handlers\DelayCalculator;
use Hibla\HttpClient\Testing\Utilities\Handlers\NetworkSimulationHandler;
use Hibla\HttpClient\Testing\Utilities\NetworkSimulator;
use Hibla\HttpClient\RetryConfig;
use Psr\Http\Message\StreamInterface;

beforeEach(function () {
    (new CacheManager())->reset();
});

afterEach(function () {
    Mockery::close();
});

describe('StandardResponseFactory', function () {
    test('creates successful response', function () {
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new StandardResponseFactory($networkHandler);

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);
        $mock->shouldReceive('shouldFail')->andReturn(false);
        $mock->shouldReceive('getBody')->andReturn('test body');
        $mock->shouldReceive('getStatusCode')->andReturn(200);
        $mock->shouldReceive('getHeaders')->andReturn(['Content-Type' => 'application/json']);

        $promise = $factory->create($mock);
        $response = $promise->await();

        expect($response)->toBeInstanceOf(Response::class)
            ->and($response->body())->toBe('test body')
            ->and($response->status())->toBe(200)
            ->and($response->headers())->toBe(['content-type' => 'application/json']);
    });

    test('handles mock failure', function () {
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new StandardResponseFactory($networkHandler);

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);
        $mock->shouldReceive('shouldFail')->andReturn(true);
        $mock->shouldReceive('getError')->andReturn('Custom error message');

        $promise = $factory->create($mock);

        expect(fn() => $promise->await())
            ->toThrow(NetworkException::class, 'Custom error message');
    });

    test('handles network failure', function () {
        $networkSimulator = createNetworkSimulatorWithFailure();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new StandardResponseFactory($networkHandler);

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);

        $promise = $factory->create($mock);

        expect(fn() => $promise->await())
            ->toThrow(NetworkException::class);
    });

    test('handles network simulation failure', function () {
        $networkSimulator = createNetworkSimulatorWithFailure();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new StandardResponseFactory($networkHandler);

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);

        $promise = $factory->create($mock);

        expect(fn() => $promise->await())
            ->toThrow(NetworkException::class);
    });

    test('can be cancelled', function () {
        $networkSimulator = createNetworkSimulator();
        $networkSimulator->enable(['default_delay' => 0.1]);
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new StandardResponseFactory($networkHandler);

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);

        $promise = $factory->create($mock);
        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    test('applies delay from mock', function () {
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new StandardResponseFactory($networkHandler);

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.05);
        $mock->shouldReceive('shouldFail')->andReturn(false);
        $mock->shouldReceive('getBody')->andReturn('delayed response');
        $mock->shouldReceive('getStatusCode')->andReturn(200);
        $mock->shouldReceive('getHeaders')->andReturn([]);

        $startTime = microtime(true);
        $promise = $factory->create($mock);
        $response = $promise->await();
        $duration = microtime(true) - $startTime;

        expect($response->body())->toBe('delayed response')
            ->and($duration)->toBeGreaterThan(0.04);
    });

    test('applies network delay', function () {
        $networkSimulator = createNetworkSimulator();
        $networkSimulator->enable(['default_delay' => 0.05]);
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new StandardResponseFactory($networkHandler);

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);
        $mock->shouldReceive('shouldFail')->andReturn(false);
        $mock->shouldReceive('getBody')->andReturn('response');
        $mock->shouldReceive('getStatusCode')->andReturn(200);
        $mock->shouldReceive('getHeaders')->andReturn([]);

        $startTime = microtime(true);
        $promise = $factory->create($mock);
        $response = $promise->await();
        $duration = microtime(true) - $startTime;

        expect($response->body())->toBe('response')
            ->and($duration)->toBeGreaterThan(0.04);
    });
});

describe('DownloadResponseFactory', function () {
    test('creates successful download', function () {
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $fileManager = createFileManager();
        $factory = new DownloadResponseFactory($networkHandler);

        $tempDir = createTempDir();

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);
        $mock->shouldReceive('shouldFail')->andReturn(false);
        $mock->shouldReceive('getBody')->andReturn('file content');
        $mock->shouldReceive('getStatusCode')->andReturn(200);
        $mock->shouldReceive('getHeaders')->andReturn(['Content-Type' => 'text/plain']);

        $destination = $tempDir . '/test.txt';
        $promise = $factory->create($mock, $destination, $fileManager);
        $result = $promise->await();

        expect($result)->toBeArray()
            ->and($result['file'])->toBe($destination)
            ->and($result['status'])->toBe(200)
            ->and($result['size'])->toBe(12)
            ->and($result['protocol_version'])->toBe('2.0')
            ->and(file_exists($destination))->toBeTrue()
            ->and(file_get_contents($destination))->toBe('file content');

        $fileManager->cleanup();
        cleanupTempDir($tempDir);
    });

    test('creates directory if not exists', function () {
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $fileManager = createFileManager();
        $factory = new DownloadResponseFactory($networkHandler);

        $tempDir = createTempDir();

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);
        $mock->shouldReceive('shouldFail')->andReturn(false);
        $mock->shouldReceive('getBody')->andReturn('content');
        $mock->shouldReceive('getStatusCode')->andReturn(200);
        $mock->shouldReceive('getHeaders')->andReturn([]);

        $destination = $tempDir . '/nested/dir/file.txt';
        $promise = $factory->create($mock, $destination, $fileManager);
        $promise->await();

        expect(is_dir($tempDir . '/nested/dir'))->toBeTrue()
            ->and(file_exists($destination))->toBeTrue();

        $fileManager->cleanup();
        cleanupTempDir($tempDir);
    });

    test('handles network failure during download', function () {
        $networkSimulator = createNetworkSimulatorWithFailure();
        $networkHandler = createNetworkHandler($networkSimulator);
        $fileManager = createFileManager();
        $factory = new DownloadResponseFactory($networkHandler);

        $tempDir = createTempDir();

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);

        $destination = $tempDir . '/test.txt';
        $promise = $factory->create($mock, $destination, $fileManager);

        expect(fn() => $promise->await())
            ->toThrow(NetworkException::class);

        $fileManager->cleanup();
        cleanupTempDir($tempDir);
    });

    test('handles mock failure during download', function () {
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $fileManager = createFileManager();
        $factory = new DownloadResponseFactory($networkHandler);

        $tempDir = createTempDir();

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);
        $mock->shouldReceive('shouldFail')->andReturn(true);
        $mock->shouldReceive('getError')->andReturn('Download failed');

        $destination = $tempDir . '/test.txt';
        $promise = $factory->create($mock, $destination, $fileManager);

        expect(fn() => $promise->await())
            ->toThrow(NetworkException::class, 'Download failed');

        $fileManager->cleanup();
        cleanupTempDir($tempDir);
    });

    test('can be cancelled during download', function () {
        $networkSimulator = createNetworkSimulator();
        $networkSimulator->enable(['default_delay' => 0.1]);
        $networkHandler = createNetworkHandler($networkSimulator);
        $fileManager = createFileManager();
        $factory = new DownloadResponseFactory($networkHandler);

        $tempDir = createTempDir();

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);

        $destination = $tempDir . '/test.txt';
        $promise = $factory->create($mock, $destination, $fileManager);
        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();

        $fileManager->cleanup();
        cleanupTempDir($tempDir);
    });

    test('tracks created files and directories', function () {
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $fileManager = createFileManager();
        $factory = new DownloadResponseFactory($networkHandler);

        $tempDir = createTempDir();

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);
        $mock->shouldReceive('shouldFail')->andReturn(false);
        $mock->shouldReceive('getBody')->andReturn('tracked content');
        $mock->shouldReceive('getStatusCode')->andReturn(200);
        $mock->shouldReceive('getHeaders')->andReturn([]);

        $destination = $tempDir . '/tracked/file.txt';
        $promise = $factory->create($mock, $destination, $fileManager);
        $promise->await();

        expect(file_exists($destination))->toBeTrue();

        $fileManager->cleanup();

        expect(file_exists($destination))->toBeFalse()
            ->and(is_dir($tempDir . '/tracked'))->toBeFalse();

        cleanupTempDir($tempDir);
    });
});

describe('StreamingResponseFactory', function () {
    test('creates successful streaming response', function () {
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new StreamingResponseFactory($networkHandler);

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);
        $mock->shouldReceive('shouldFail')->andReturn(false);
        $mock->shouldReceive('getBody')->andReturn('stream content');
        $mock->shouldReceive('getStatusCode')->andReturn(200);
        $mock->shouldReceive('getHeaders')->andReturn(['Content-Type' => 'text/event-stream']);
        $mock->shouldReceive('getBodySequence')->andReturn([]);

        $stream = Mockery::mock(StreamInterface::class);
        $createStream = fn($body) => $stream;

        $promise = $factory->create($mock, null, $createStream);
        $response = $promise->await();

        expect($response)->toBeInstanceOf(StreamingResponse::class)
            ->and($response->status())->toBe(200)
            ->and($response->headers())->toBe(['content-type' => 'text/event-stream']);
    });

    test('processes chunks with callback', function () {
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new StreamingResponseFactory($networkHandler);

        $chunks = [];
        $onChunk = function ($chunk) use (&$chunks) {
            $chunks[] = $chunk;
        };

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);
        $mock->shouldReceive('shouldFail')->andReturn(false);
        $mock->shouldReceive('getBody')->andReturn('full content');
        $mock->shouldReceive('getStatusCode')->andReturn(200);
        $mock->shouldReceive('getHeaders')->andReturn([]);
        $mock->shouldReceive('getBodySequence')->andReturn(['chunk1', 'chunk2', 'chunk3']);

        $stream = Mockery::mock(StreamInterface::class);
        $createStream = fn($body) => $stream;

        $promise = $factory->create($mock, $onChunk, $createStream);
        $promise->await();

        expect($chunks)->toBe(['chunk1', 'chunk2', 'chunk3']);
    });

    test('handles single chunk when no sequence', function () {
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new StreamingResponseFactory($networkHandler);

        $chunks = [];
        $onChunk = function ($chunk) use (&$chunks) {
            $chunks[] = $chunk;
        };

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);
        $mock->shouldReceive('shouldFail')->andReturn(false);
        $mock->shouldReceive('getBody')->andReturn('single chunk');
        $mock->shouldReceive('getStatusCode')->andReturn(200);
        $mock->shouldReceive('getHeaders')->andReturn([]);
        $mock->shouldReceive('getBodySequence')->andReturn([]);

        $stream = Mockery::mock(StreamInterface::class);
        $createStream = fn($body) => $stream;

        $promise = $factory->create($mock, $onChunk, $createStream);
        $promise->await();

        expect($chunks)->toBe(['single chunk']);
    });

    test('handles network failure', function () {
        $networkSimulator = createNetworkSimulatorWithFailure();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new StreamingResponseFactory($networkHandler);

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);

        $stream = Mockery::mock(StreamInterface::class);
        $createStream = fn($body) => $stream;

        $promise = $factory->create($mock, null, $createStream);

        expect(fn() => $promise->await())
            ->toThrow(HttpException::class);
    });

    test('handles mock failure', function () {
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new StreamingResponseFactory($networkHandler);

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);
        $mock->shouldReceive('shouldFail')->andReturn(true);
        $mock->shouldReceive('getError')->andReturn('Stream error');

        $stream = Mockery::mock(StreamInterface::class);
        $createStream = fn($body) => $stream;

        $promise = $factory->create($mock, null, $createStream);

        expect(fn() => $promise->await())
            ->toThrow(HttpException::class, 'Stream error');
    });

    test('can be cancelled', function () {
        $networkSimulator = createNetworkSimulator();
        $networkSimulator->enable(['default_delay' => 0.1]);
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new StreamingResponseFactory($networkHandler);

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);

        $stream = Mockery::mock(StreamInterface::class);
        $createStream = fn($body) => $stream;

        $promise = $factory->create($mock, null, $createStream);
        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    test('does not call onChunk when null', function () {
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new StreamingResponseFactory($networkHandler);

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);
        $mock->shouldReceive('shouldFail')->andReturn(false);
        $mock->shouldReceive('getBody')->andReturn('content');
        $mock->shouldReceive('getStatusCode')->andReturn(200);
        $mock->shouldReceive('getHeaders')->andReturn([]);
        $mock->shouldReceive('getBodySequence')->andReturn([]);

        $stream = Mockery::mock(StreamInterface::class);
        $createStream = fn($body) => $stream;

        $promise = $factory->create($mock, null, $createStream);
        $response = $promise->await();

        expect($response)->toBeInstanceOf(StreamingResponse::class);
    });
});

describe('RetryableResponseFactory', function () {
    test('succeeds on first attempt', function () {
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new RetryableResponseFactory($networkHandler);

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);
        $mock->shouldReceive('shouldFail')->andReturn(false);
        $mock->shouldReceive('getBody')->andReturn('success');
        $mock->shouldReceive('getStatusCode')->andReturn(200);
        $mock->shouldReceive('getHeaders')->andReturn([]);

        $retryConfig = new RetryConfig(maxRetries: 3);
        $mockProvider = fn($attempt) => $mock;

        $promise = $factory->create($retryConfig, $mockProvider);
        $response = $promise->await();

        expect($response)->toBeInstanceOf(Response::class)
            ->and($response->body())->toBe('success')
            ->and($response->status())->toBe(200);
    });

    test('retries on network failure and succeeds', function () {
        $attemptCount = 0;

        // Create a simulator that will fail the first 2 attempts
        $networkSimulator = createNetworkSimulator();
        $networkSimulator->enable([
            'retryable_failure_rate' => 0.0, // Will be controlled per attempt
            'default_delay' => 0.0,
        ]);

        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new RetryableResponseFactory($networkHandler);

        $retryConfig = new RetryConfig(
            maxRetries: 3,
            baseDelay: 0.01,
            jitter: false
        );

        $mockProvider = function ($attempt) use (&$attemptCount) {
            $attemptCount++;
            $mock = Mockery::mock(MockedRequest::class);
            $mock->shouldReceive('getDelay')->andReturn(0.0);

            if ($attempt < 3) {
                $mock->shouldReceive('shouldFail')->andReturn(true);
                $mock->shouldReceive('getError')->andReturn('timeout');
                $mock->shouldReceive('isRetryableFailure')->andReturn(false);
            } else {
                $mock->shouldReceive('shouldFail')->andReturn(false);
                $mock->shouldReceive('getBody')->andReturn('success after retry');
                $mock->shouldReceive('getStatusCode')->andReturn(200);
                $mock->shouldReceive('getHeaders')->andReturn([]);
            }

            return $mock;
        };

        $promise = $factory->create($retryConfig, $mockProvider);
        $response = $promise->await();

        expect($response)->toBeInstanceOf(Response::class)
            ->and($attemptCount)->toBe(3);
    });

    test('retries on retryable status code', function () {
        $attemptCount = 0;
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new RetryableResponseFactory($networkHandler);

        $retryConfig = new RetryConfig(
            maxRetries: 3,
            retryableStatusCodes: [503],
            baseDelay: 0.01,
            jitter: false
        );

        $mockProvider = function ($attempt) use (&$attemptCount) {
            $attemptCount++;
            $mock = Mockery::mock(MockedRequest::class);
            $mock->shouldReceive('getDelay')->andReturn(0.0);
            $mock->shouldReceive('shouldFail')->andReturn(false);
            $mock->shouldReceive('getStatusCode')->andReturn($attempt < 3 ? 503 : 200);
            $mock->shouldReceive('getBody')->andReturn('success');
            $mock->shouldReceive('getHeaders')->andReturn([]);

            return $mock;
        };

        $promise = $factory->create($retryConfig, $mockProvider);
        $response = $promise->await();

        expect($response->status())->toBe(200)
            ->and($attemptCount)->toBe(3);
    });

    test('retries on mock retryable failure', function () {
        $attemptCount = 0;
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new RetryableResponseFactory($networkHandler);

        $retryConfig = new RetryConfig(
            maxRetries: 3,
            baseDelay: 0.01,
            jitter: false
        );

        $mockProvider = function ($attempt) use (&$attemptCount) {
            $attemptCount++;
            $mock = Mockery::mock(MockedRequest::class);
            $mock->shouldReceive('getDelay')->andReturn(0.0);
            $mock->shouldReceive('shouldFail')->andReturn($attempt < 3);
            $mock->shouldReceive('getError')->andReturn('Retryable error');
            $mock->shouldReceive('isRetryableFailure')->andReturn(true);

            if ($attempt >= 3) {
                $mock->shouldReceive('getBody')->andReturn('success');
                $mock->shouldReceive('getStatusCode')->andReturn(200);
                $mock->shouldReceive('getHeaders')->andReturn([]);
            }

            return $mock;
        };

        $promise = $factory->create($retryConfig, $mockProvider);
        $response = $promise->await();

        expect($response->status())->toBe(200)
            ->and($attemptCount)->toBe(3);
    });

    test('fails after max retries', function () {
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new RetryableResponseFactory($networkHandler);

        $retryConfig = new RetryConfig(
            maxRetries: 3,
            baseDelay: 0.01,
            jitter: false
        );

        $mockProvider = function ($attempt) {
            $mock = Mockery::mock(MockedRequest::class);
            $mock->shouldReceive('getDelay')->andReturn(0.0);
            $mock->shouldReceive('shouldFail')->andReturn(true);
            $mock->shouldReceive('getError')->andReturn('timeout');
            $mock->shouldReceive('isRetryableFailure')->andReturn(false);

            return $mock;
        };

        $promise = $factory->create($retryConfig, $mockProvider);

        expect(fn() => $promise->await())
            ->toThrow(NetworkException::class, 'HTTP Request failed after 4 attempt(s)');
    });

    test('does not retry non-retryable errors', function () {
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new RetryableResponseFactory($networkHandler);

        $retryConfig = new RetryConfig(
            maxRetries: 3,
            retryableExceptions: ['timeout']
        );

        $mockProvider = function ($attempt) {
            $mock = Mockery::mock(MockedRequest::class);
            $mock->shouldReceive('getDelay')->andReturn(0.0);
            $mock->shouldReceive('shouldFail')->andReturn(true);
            $mock->shouldReceive('getError')->andReturn('Fatal error');
            $mock->shouldReceive('isRetryableFailure')->andReturn(false);

            return $mock;
        };

        $promise = $factory->create($retryConfig, $mockProvider);

        expect(fn() => $promise->await())
            ->toThrow(NetworkException::class, 'HTTP Request failed after 1 attempt(s): Fatal error');
    });

    test('does not retry non-retryable status codes', function () {
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new RetryableResponseFactory($networkHandler);

        $retryConfig = new RetryConfig(
            maxRetries: 3,
            retryableStatusCodes: [503]
        );

        $mockProvider = function ($attempt) {
            $mock = Mockery::mock(MockedRequest::class);
            $mock->shouldReceive('getDelay')->andReturn(0.0);
            $mock->shouldReceive('shouldFail')->andReturn(false);
            $mock->shouldReceive('getStatusCode')->andReturn(404);

            return $mock;
        };

        $promise = $factory->create($retryConfig, $mockProvider);

        expect(fn() => $promise->await())
            ->toThrow(NetworkException::class, 'HTTP Request failed after 1 attempt(s): Mock responded with status 404');
    });

    test('can be cancelled during retry', function () {
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new RetryableResponseFactory($networkHandler);

        $retryConfig = new RetryConfig(
            maxRetries: 3,
            baseDelay: 0.1
        );

        $mockProvider = function ($attempt) {
            $mock = Mockery::mock(MockedRequest::class);
            $mock->shouldReceive('getDelay')->andReturn(0.0);
            $mock->shouldReceive('shouldFail')->andReturn(true);
            $mock->shouldReceive('getError')->andReturn('timeout');
            $mock->shouldReceive('isRetryableFailure')->andReturn(false);

            return $mock;
        };

        $promise = $factory->create($retryConfig, $mockProvider);
        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();
    });

    test('applies exponential backoff between retries', function () {
        $attemptCount = 0;
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new RetryableResponseFactory($networkHandler);

        $retryConfig = new RetryConfig(
            maxRetries: 3,
            baseDelay: 0.02,
            backoffMultiplier: 2.0,
            jitter: false
        );

        $mockProvider = function ($attempt) use (&$attemptCount) {
            $attemptCount++;
            $mock = Mockery::mock(MockedRequest::class);
            $mock->shouldReceive('getDelay')->andReturn(0.0);
            $mock->shouldReceive('shouldFail')->andReturn($attempt < 4);
            $mock->shouldReceive('getError')->andReturn('timeout');
            $mock->shouldReceive('isRetryableFailure')->andReturn(true);

            if ($attempt >= 4) {
                $mock->shouldReceive('getBody')->andReturn('success');
                $mock->shouldReceive('getStatusCode')->andReturn(200);
                $mock->shouldReceive('getHeaders')->andReturn([]);
            }

            return $mock;
        };

        $startTime = microtime(true);
        $promise = $factory->create($retryConfig, $mockProvider);
        $response = $promise->await();
        $duration = microtime(true) - $startTime;

        // Total delay should be: 0.02 + 0.04 + 0.08 = 0.14
        expect($response->status())->toBe(200)
            ->and($duration)->toBeGreaterThan(0.13)
            ->and($attemptCount)->toBe(4);
    });

    test('handles mock provider exception', function () {
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new RetryableResponseFactory($networkHandler);

        $retryConfig = new RetryConfig(maxRetries: 3);
        $mockProvider = fn($attempt) => throw new Exception('Provider error');

        $promise = $factory->create($retryConfig, $mockProvider);

        expect(fn() => $promise->await())
            ->toThrow(Exception::class, 'Mock provider error: Provider error');
    });

    test('handles mock provider returning invalid type', function () {
        $networkSimulator = createNetworkSimulator();
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new RetryableResponseFactory($networkHandler);

        $retryConfig = new RetryConfig(maxRetries: 3);
        $mockProvider = fn($attempt) => 'not a mock';

        $promise = $factory->create($retryConfig, $mockProvider);

        expect(fn() => $promise->await())
            ->toThrow(Exception::class, 'Mock provider must return a MockedRequest instance');
    });

    test('retries with retryable network failure', function () {
        $attemptCount = 0;
        $networkSimulator = createNetworkSimulatorWithRetryableFailure(1.0);
        $networkHandler = createNetworkHandler($networkSimulator);
        $factory = new RetryableResponseFactory($networkHandler);

        $retryConfig = new RetryConfig(
            maxRetries: 2,
            baseDelay: 0.01,
            jitter: false
        );

        $mockProvider = function ($attempt) use (&$attemptCount) {
            $attemptCount++;
            $mock = Mockery::mock(MockedRequest::class);
            $mock->shouldReceive('getDelay')->andReturn(0.0);

            return $mock;
        };

        $promise = $factory->create($retryConfig, $mockProvider);

        expect(fn() => $promise->await())
            ->toThrow(NetworkException::class)
            ->and($attemptCount)->toBeGreaterThan(1);
    });
});

describe('DelayCalculator', function () {
    test('returns maximum delay from all sources', function () {
        $calculator = new DelayCalculator();

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.5);

        $networkConditions = ['delay' => 0.3];
        $globalDelay = 0.7;

        $result = $calculator->calculateTotalDelay($mock, $networkConditions, $globalDelay);

        expect($result)->toBe(0.7);
    });

    test('handles missing network delay', function () {
        $calculator = new DelayCalculator();

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.5);

        $networkConditions = [];
        $globalDelay = 0.2;

        $result = $calculator->calculateTotalDelay($mock, $networkConditions, $globalDelay);

        expect($result)->toBe(0.5);
    });

    test('returns mock delay when it is the maximum', function () {
        $calculator = new DelayCalculator();

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.8);

        $networkConditions = ['delay' => 0.3];
        $globalDelay = 0.2;

        $result = $calculator->calculateTotalDelay($mock, $networkConditions, $globalDelay);

        expect($result)->toBe(0.8);
    });

    test('returns network delay when it is the maximum', function () {
        $calculator = new DelayCalculator();

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.2);

        $networkConditions = ['delay' => 0.9];
        $globalDelay = 0.3;

        $result = $calculator->calculateTotalDelay($mock, $networkConditions, $globalDelay);

        expect($result)->toBe(0.9);
    });

    test('handles all zero delays', function () {
        $calculator = new DelayCalculator();

        $mock = Mockery::mock(MockedRequest::class);
        $mock->shouldReceive('getDelay')->andReturn(0.0);

        $networkConditions = ['delay' => 0.0];
        $globalDelay = 0.0;

        $result = $calculator->calculateTotalDelay($mock, $networkConditions, $globalDelay);

        expect($result)->toBe(0.0);
    });
});

describe('RetryConfig', function () {
    test('creates with default values', function () {
        $config = new RetryConfig();

        expect($config->maxRetries)->toBe(3)
            ->and($config->baseDelay)->toBe(1.0)
            ->and($config->maxDelay)->toBe(60.0)
            ->and($config->backoffMultiplier)->toBe(2.0)
            ->and($config->jitter)->toBeTrue()
            ->and($config->retryableStatusCodes)->toBe([408, 429, 500, 502, 503, 504])
            ->and($config->retryableExceptions)->toContain('timeout');
    });

    test('creates with custom values', function () {
        $config = new RetryConfig(
            maxRetries: 5,
            baseDelay: 0.5,
            maxDelay: 30.0,
            backoffMultiplier: 3.0,
            jitter: false,
            retryableStatusCodes: [500, 503],
            retryableExceptions: ['custom error']
        );

        expect($config->maxRetries)->toBe(5)
            ->and($config->baseDelay)->toBe(0.5)
            ->and($config->maxDelay)->toBe(30.0)
            ->and($config->backoffMultiplier)->toBe(3.0)
            ->and($config->jitter)->toBeFalse()
            ->and($config->retryableStatusCodes)->toBe([500, 503])
            ->and($config->retryableExceptions)->toBe(['custom error']);
    });

    test('shouldRetry returns false when max retries exceeded', function () {
        $config = new RetryConfig(maxRetries: 3);

        expect($config->shouldRetry(4))->toBeFalse()
            ->and($config->shouldRetry(5))->toBeFalse();
    });

    test('shouldRetry returns true for retryable status codes', function () {
        $config = new RetryConfig(retryableStatusCodes: [503, 429]);

        expect($config->shouldRetry(1, 503))->toBeTrue()
            ->and($config->shouldRetry(1, 429))->toBeTrue()
            ->and($config->shouldRetry(1, 404))->toBeFalse();
    });

    test('shouldRetry returns true for retryable errors', function () {
        $config = new RetryConfig(retryableExceptions: ['timeout', 'connection failed']);

        expect($config->shouldRetry(1, null, 'Connection timeout occurred'))->toBeTrue()
            ->and($config->shouldRetry(1, null, 'The connection failed unexpectedly'))->toBeTrue()
            ->and($config->shouldRetry(1, null, 'Not found'))->toBeFalse();
    });

    test('shouldRetry is case insensitive for errors', function () {
        $config = new RetryConfig(retryableExceptions: ['timeout']);

        expect($config->shouldRetry(1, null, 'TIMEOUT ERROR'))->toBeTrue()
            ->and($config->shouldRetry(1, null, 'Timeout'))->toBeTrue()
            ->and($config->shouldRetry(1, null, 'timeout'))->toBeTrue();
    });

    test('isRetryableError checks for substring match', function () {
        $config = new RetryConfig(retryableExceptions: ['timeout']);

        expect($config->isRetryableError('Connection timeout'))->toBeTrue()
            ->and($config->isRetryableError('Request timed out'))->toBeFalse()
            ->and($config->isRetryableError('timeout'))->toBeTrue();
    });

    test('getDelay calculates exponential backoff', function () {
        $config = new RetryConfig(
            baseDelay: 1.0,
            backoffMultiplier: 2.0,
            jitter: false
        );

        expect($config->getDelay(1))->toBe(1.0)
            ->and($config->getDelay(2))->toBe(2.0)
            ->and($config->getDelay(3))->toBe(4.0)
            ->and($config->getDelay(4))->toBe(8.0);
    });

    test('getDelay respects max delay', function () {
        $config = new RetryConfig(
            baseDelay: 1.0,
            maxDelay: 5.0,
            backoffMultiplier: 2.0,
            jitter: false
        );

        expect($config->getDelay(1))->toBe(1.0)
            ->and($config->getDelay(2))->toBe(2.0)
            ->and($config->getDelay(3))->toBe(4.0)
            ->and($config->getDelay(4))->toBe(5.0)
            ->and($config->getDelay(5))->toBe(5.0);
    });

    test('getDelay applies jitter', function () {
        $config = new RetryConfig(
            baseDelay: 1.0,
            backoffMultiplier: 2.0,
            jitter: true
        );

        $delays = [];
        for ($i = 0; $i < 10; $i++) {
            $delays[] = $config->getDelay(2);
        }

        $uniqueDelays = array_unique($delays);
        expect(count($uniqueDelays))->toBeGreaterThan(1);

        foreach ($delays as $delay) {
            expect($delay)->toBeGreaterThanOrEqual(1.5)
                ->and($delay)->toBeLessThanOrEqual(2.5);
        }
    });

    test('getDelay never returns negative value', function () {
        $config = new RetryConfig(
            baseDelay: 0.01,
            jitter: true
        );

        for ($i = 1; $i <= 5; $i++) {
            expect($config->getDelay($i))->toBeGreaterThanOrEqual(0);
        }
    });

    test('getDelay with different backoff multipliers', function () {
        $config = new RetryConfig(
            baseDelay: 1.0,
            backoffMultiplier: 3.0,
            jitter: false
        );

        expect($config->getDelay(1))->toBe(1.0)
            ->and($config->getDelay(2))->toBe(3.0)
            ->and($config->getDelay(3))->toBe(9.0);
    });

    test('retryableExceptions contains expected default errors', function () {
        $config = new RetryConfig();

        expect($config->retryableExceptions)->toContain('timeout')
            ->and($config->retryableExceptions)->toContain('cURL error')
            ->and($config->retryableExceptions)->toContain('connection failed')
            ->and($config->retryableExceptions)->toContain('Could not resolve host');
    });

    test('shouldRetry prioritizes status code check', function () {
        $config = new RetryConfig(
            maxRetries: 3,
            retryableStatusCodes: [503]
        );

        // Should retry even without error message
        expect($config->shouldRetry(1, 503))->toBeTrue()
            ->and($config->shouldRetry(1, 503, null))->toBeTrue();
    });

    test('shouldRetry with both status code and error', function () {
        $config = new RetryConfig(
            maxRetries: 3,
            retryableStatusCodes: [503],
            retryableExceptions: ['timeout']
        );

        expect($config->shouldRetry(1, 503, 'Some error'))->toBeTrue()
            ->and($config->shouldRetry(1, 404, 'timeout'))->toBeTrue()
            ->and($config->shouldRetry(1, 404, 'not found'))->toBeFalse();
    });
});

describe('NetworkSimulator', function () {
    test('returns no failure when disabled', function () {
        $simulator = new NetworkSimulator();
        $result = $simulator->simulate();

        expect($result['should_fail'])->toBeFalse()
            ->and($result['should_timeout'])->toBeFalse()
            ->and($result['error_message'])->toBeNull()
            ->and($result['delay'])->toBe(0.0);
    });

    test('simulates network failure', function () {
        $simulator = new NetworkSimulator();
        $simulator->enable(['failure_rate' => 1.0, 'default_delay' => 0.0]);

        $result = $simulator->simulate();

        expect($result['should_fail'])->toBeTrue()
            ->and($result['error_message'])->toBe('Simulated network failure');
    });

    test('simulates timeout', function () {
        $simulator = new NetworkSimulator();
        $simulator->enable(['timeout_rate' => 1.0, 'timeout_delay' => 0.5, 'default_delay' => 0.0]);

        $result = $simulator->simulate();

        expect($result['should_timeout'])->toBeTrue()
            ->and($result['error_message'])->toContain('timed out');
    });

    test('simulates retryable failure', function () {
        $simulator = new NetworkSimulator();
        $simulator->enable(['retryable_failure_rate' => 1.0, 'default_delay' => 0.0]);

        $result = $simulator->simulate();

        expect($result['should_fail'])->toBeTrue()
            ->and($result['error_message'])->not->toBeNull();
    });

    test('simulates connection failure', function () {
        $simulator = new NetworkSimulator();
        $simulator->enable(['connection_failure_rate' => 1.0, 'default_delay' => 0.0]);

        $result = $simulator->simulate();

        expect($result['should_fail'])->toBeTrue()
            ->and($result['error_message'])->toContain('Connection refused');
    });

    test('applies default delay', function () {
        $simulator = new NetworkSimulator();
        $simulator->enable(['default_delay' => 0.5]);

        $result = $simulator->simulate();

        expect($result['delay'])->toBe(0.5);
    });

    test('applies random delay range', function () {
        $simulator = new NetworkSimulator();
        $simulator->setRandomDelay([0.1, 0.3]);
        $simulator->enable();

        $result = $simulator->simulate();

        expect($result['delay'])->toBeGreaterThanOrEqual(0.1)
            ->and($result['delay'])->toBeLessThanOrEqual(0.3);
    });

    test('getDefaultDelay returns configured delay', function () {
        $simulator = new NetworkSimulator();
        $simulator->enable(['default_delay' => 0.7]);

        expect($simulator->getDefaultDelay())->toBe(0.7);
    });

    test('getTimeoutDelay returns configured timeout delay', function () {
        $simulator = new NetworkSimulator();
        $simulator->enable(['timeout_delay' => 30.0]);

        expect($simulator->getTimeoutDelay())->toBe(30.0);
    });

    test('handles delay as array of choices', function () {
        $simulator = new NetworkSimulator();
        $simulator->enable(['default_delay' => [0.1, 0.2, 0.3]]);

        $result = $simulator->simulate();

        expect($result['delay'])->toBeIn([0.1, 0.2, 0.3]);
    });

    test('disable stops simulation', function () {
        $simulator = new NetworkSimulator();
        $simulator->enable(['failure_rate' => 1.0]);
        $simulator->disable();

        $result = $simulator->simulate();

        expect($result['should_fail'])->toBeFalse();
    });
});

describe('NetworkSimulationHandler', function () {
    test('simulates network conditions', function () {
        $networkSimulator = new NetworkSimulator();
        $networkSimulator->enable(['failure_rate' => 1.0, 'default_delay' => 0.0]);

        $handler = new NetworkSimulationHandler($networkSimulator);
        $result = $handler->simulate();

        expect($result)->toHaveKey('should_fail')
            ->and($result)->toHaveKey('delay')
            ->and($result['should_fail'])->toBeTrue();
    });

    test('handles successful simulation', function () {
        $networkSimulator = new NetworkSimulator();

        $handler = new NetworkSimulationHandler($networkSimulator);
        $result = $handler->simulate();

        expect($result['should_fail'])->toBeFalse()
            ->and($result['delay'])->toBe(0.0);
    });

    test('generateGlobalRandomDelay returns zero without handler', function () {
        $networkSimulator = new NetworkSimulator();
        $handler = new NetworkSimulationHandler($networkSimulator, null);

        expect($handler->generateGlobalRandomDelay())->toBe(0.0);
    });

    test('preserves error message for network failure', function () {
        $networkSimulator = new NetworkSimulator();
        $networkSimulator->enable([
            'failure_rate' => 1.0,
            'default_delay' => 0.0
        ]);

        $handler = new NetworkSimulationHandler($networkSimulator);
        $result = $handler->simulate();

        expect($result)->toHaveKey('should_fail')
            ->and($result['should_fail'])->toBeTrue()
            ->and($result)->toHaveKey('error_message')
            ->and($result['error_message'])->toBe('Simulated network failure');
    });

    test('preserves error message for retryable failure', function () {
        $networkSimulator = new NetworkSimulator();
        $networkSimulator->enable([
            'retryable_failure_rate' => 1.0,
            'default_delay' => 0.0
        ]);

        $handler = new NetworkSimulationHandler($networkSimulator);
        $result = $handler->simulate();

        expect($result)->toHaveKey('should_fail')
            ->and($result['should_fail'])->toBeTrue()
            ->and($result)->toHaveKey('error_message')
            ->and($result['error_message'])->toContain('network simulation');
    });

    test('preserves error message for connection failure', function () {
        $networkSimulator = new NetworkSimulator();
        $networkSimulator->enable([
            'connection_failure_rate' => 1.0,
            'default_delay' => 0.0
        ]);

        $handler = new NetworkSimulationHandler($networkSimulator);
        $result = $handler->simulate();

        expect($result)->toHaveKey('should_fail')
            ->and($result['should_fail'])->toBeTrue()
            ->and($result)->toHaveKey('error_message')
            ->and($result['error_message'])->toContain('Connection refused');
    });
});

describe('CacheHandler', function () {
    test('tryServeFromCache returns false when cache is null', function () {
        $cacheManager = createCacheManager();
        $handler = new CacheHandler($cacheManager);

        $result = $handler->tryServeFromCache('http://example.com', 'GET', null);

        expect($result)->toBeFalse();
    });

    test('tryServeFromCache returns false for non-GET requests', function () {
        $cacheManager = createCacheManager();
        $handler = new CacheHandler($cacheManager);
        $cacheConfig = new CacheConfig(ttlSeconds: 60);

        $result = $handler->tryServeFromCache('http://example.com', 'POST', $cacheConfig);

        expect($result)->toBeFalse();
    });

    test('tryServeFromCache returns true when cache hit', function () {
        $cacheManager = createCacheManager();
        $cacheConfig = new CacheConfig(ttlSeconds: 60);

        $response = new Response('cached content', 200, []);
        $cacheManager->cacheResponse('http://example.com', $response, $cacheConfig);

        $handler = new CacheHandler($cacheManager);
        $result = $handler->tryServeFromCache('http://example.com', 'GET', $cacheConfig);

        expect($result)->toBeTrue();
    });

    test('getCachedResponse returns cached response', function () {
        $cacheManager = createCacheManager();
        $cacheConfig = new CacheConfig(ttlSeconds: 60);

        $response = new Response('cached content', 200, []);
        $cacheManager->cacheResponse('http://example.com', $response, $cacheConfig);

        $handler = new CacheHandler($cacheManager);
        $cachedResponse = $handler->getCachedResponse('http://example.com', $cacheConfig);

        expect($cachedResponse)->toBeInstanceOf(Response::class)
            ->and($cachedResponse->body())->toBe('cached content');
    });

    test('cacheIfNeeded caches successful GET responses', function () {
        $cacheManager = createCacheManager();
        $handler = new CacheHandler($cacheManager);
        $cacheConfig = new CacheConfig(ttlSeconds: 60);

        $response = new Response('new content', 200, []);
        $handler->cacheIfNeeded('http://example.com', $response, $cacheConfig, 'GET');

        $cached = $cacheManager->getCachedResponse('http://example.com', $cacheConfig);
        expect($cached)->not->toBeNull()
            ->and($cached->body())->toBe('new content');
    });

    test('cacheIfNeeded does not cache POST requests', function () {
        $cacheManager = createCacheManager();
        $handler = new CacheHandler($cacheManager);
        $cacheConfig = new CacheConfig(ttlSeconds: 60);

        $response = new Response('content', 200, []);
        $handler->cacheIfNeeded('http://example.com', $response, $cacheConfig, 'POST');

        $cached = $cacheManager->getCachedResponse('http://example.com', $cacheConfig);
        expect($cached)->toBeNull();
    });


    test('cacheIfNeeded does not cache failed responses', function () {
        $cacheManager = createCacheManager();
        $handler = new CacheHandler($cacheManager);
        $cacheConfig = new CacheConfig(ttlSeconds: 60);

        $response = new Response('error', 404, []);
        $handler->cacheIfNeeded('http://example.com', $response, $cacheConfig, 'GET');

        $cached = $cacheManager->getCachedResponse('http://example.com', $cacheConfig);
        expect($cached)->toBeNull();
    });

    test('cacheResponse caches response regardless of method', function () {
        $cacheManager = createCacheManager();
        $handler = new CacheHandler($cacheManager);
        $cacheConfig = new CacheConfig(ttlSeconds: 60);

        $response = new Response('content', 200, []);
        $handler->cacheResponse('http://example.com', $response, $cacheConfig);

        $cached = $cacheManager->getCachedResponse('http://example.com', $cacheConfig);
        expect($cached)->not->toBeNull();
    });
});
