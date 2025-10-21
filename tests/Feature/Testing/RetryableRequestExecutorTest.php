<?php

use Hibla\HttpClient\Response;
use Hibla\HttpClient\RetryConfig;
use Hibla\HttpClient\StreamingResponse;
use Hibla\HttpClient\Testing\Exceptions\MockException;
use Hibla\HttpClient\Testing\MockedRequest;
use Hibla\HttpClient\Testing\Utilities\Executors\RetryableRequestExecutor;
use Hibla\HttpClient\Testing\Utilities\FileManager;
use Hibla\HttpClient\Testing\Utilities\NetworkSimulator;
use Hibla\HttpClient\Testing\Utilities\RequestMatcher;
use Hibla\HttpClient\Testing\Utilities\RequestRecorder;
use Hibla\HttpClient\Testing\Utilities\ResponseFactory;
use Psr\Http\Message\StreamInterface;

function createRetryableExecutor(): RetryableRequestExecutor
{
    return new RetryableRequestExecutor(
        new RequestMatcher(),
        new ResponseFactory(new NetworkSimulator()),
        new RequestRecorder()
    );
}

test('executes request with retry on first attempt success', function () {
    $executor = createRetryableExecutor();
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/data');
    $mock->setBody('{"success": true}');
    $mocks[] = $mock;

    $retryConfig = new RetryConfig(maxRetries: 3);

    $result = $executor->executeWithRetry(
        'https://api.example.com/data',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $retryConfig,
        'GET',
        $mocks
    )->await();

    expect($result)->toBeInstanceOf(Response::class)
        ->and($result->body())->toBe('{"success": true}')
        ->and($mocks)->toBeEmpty()
    ;
});

test('retries failed request until success', function () {
    $executor = createRetryableExecutor();
    $mocks = [];

    // First attempt fails
    $mock1 = new MockedRequest('GET');
    $mock1->setUrlPattern('https://api.example.com/retry');
    $mock1->setError('Connection timeout');
    $mock1->setRetryable(true);
    $mocks[] = $mock1;

    // Second attempt succeeds
    $mock2 = new MockedRequest('GET');
    $mock2->setUrlPattern('https://api.example.com/retry');
    $mock2->setBody('{"retried": true}');
    $mocks[] = $mock2;

    $retryConfig = new RetryConfig(maxRetries: 3, baseDelay: 0.05);

    $result = $executor->executeWithRetry(
        'https://api.example.com/retry',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $retryConfig,
        'GET',
        $mocks
    )->await();

    expect($result->body())->toBe('{"retried": true}')
        ->and($mocks)->toBeEmpty()
    ;
});

test('exhausts all retry attempts and fails', function () {
    $executor = createRetryableExecutor();
    $mocks = [];

    for ($i = 0; $i < 3; $i++) {
        $mock = new MockedRequest('GET');
        $mock->setUrlPattern('https://api.example.com/always-fails');
        $mock->setError('Connection refused');
        $mock->setRetryable(true);
        $mocks[] = $mock;
    }

    $retryConfig = new RetryConfig(maxRetries: 3, baseDelay: 0.05);

    $executor->executeWithRetry(
        'https://api.example.com/always-fails',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $retryConfig,
        'GET',
        $mocks
    )->await();
})->throws(MockException::class);

test('persistent mock is not removed during retries', function () {
    $executor = createRetryableExecutor();
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/persistent');
    $mock->setBody('{"persistent": true}');
    $mock->setPersistent(true);
    $mocks[] = $mock;

    $retryConfig = new RetryConfig(maxRetries: 3);

    $result = $executor->executeWithRetry(
        'https://api.example.com/persistent',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $retryConfig,
        'GET',
        $mocks
    )->await();

    expect($result->body())->toBe('{"persistent": true}')
        ->and($mocks)->toHaveCount(1)
    ;
});

test('executeWithMockRetry handles basic request', function () {
    $executor = createRetryableExecutor();
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/data');
    $mock->setBody('{"data": "value"}');
    $mocks[] = $mock;

    $retryConfig = new RetryConfig(maxRetries: 2);

    $result = $executor->executeWithMockRetry(
        'https://api.example.com/data',
        ['method' => 'GET'],
        $retryConfig,
        'GET',
        $mocks
    )->await();

    expect($result)->toBeInstanceOf(Response::class)
        ->and($result->body())->toBe('{"data": "value"}')
    ;
});

test('executeWithMockRetry handles download', function () {
    $executor = createRetryableExecutor();
    $mocks = [];
    $fileManager = new FileManager();

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/file.pdf');
    $mock->setBody('PDF content here');
    $mocks[] = $mock;

    $retryConfig = new RetryConfig(maxRetries: 2);
    $destPath = $fileManager->createTempFile();

    $result = $executor->executeWithMockRetry(
        'https://api.example.com/file.pdf',
        [
            'method' => 'GET',
            'download' => $destPath,
        ],
        $retryConfig,
        'GET',
        $mocks,
        null,
        $fileManager
    )->await();

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('file')
        ->and($result)->toHaveKey('status')
        ->and($result['status'])->toBe(200)
        ->and(file_exists($result['file']))->toBeTrue()
        ->and(file_get_contents($result['file']))->toBe('PDF content here')
    ;

    @unlink($result['file']);
});

test('executeWithMockRetry handles streaming', function () {
    $executor = createRetryableExecutor();
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/stream');
    $mock->setBody('streaming content');
    $mocks[] = $mock;

    $retryConfig = new RetryConfig(maxRetries: 2);
    $chunkReceived = null;

    $createStream = function (string $body) use (&$streamCreated): StreamInterface {
        $streamCreated = true;
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $body);
        rewind($stream);

        return new class ($stream) implements StreamInterface {
            private $stream;

            public function __construct($stream)
            {
                $this->stream = $stream;
            }

            public function __toString(): string
            {
                return stream_get_contents($this->stream);
            }

            public function close(): void
            {
                fclose($this->stream);
            }

            public function detach()
            {
                return $this->stream;
            }

            public function getSize(): ?int
            {
                return null;
            }

            public function tell(): int
            {
                return ftell($this->stream);
            }

            public function eof(): bool
            {
                return feof($this->stream);
            }

            public function isSeekable(): bool
            {
                return true;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                fseek($this->stream, $offset, $whence);
            }

            public function rewind(): void
            {
                rewind($this->stream);
            }

            public function isWritable(): bool
            {
                return true;
            }

            public function write(string $string): int
            {
                return fwrite($this->stream, $string);
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function read(int $length): string
            {
                return fread($this->stream, $length);
            }

            public function getContents(): string
            {
                return stream_get_contents($this->stream);
            }

            public function getMetadata(?string $key = null)
            {
                return null;
            }
        };
    };

    $result = $executor->executeWithMockRetry(
        'https://api.example.com/stream',
        [
            'method' => 'GET',
            'stream' => true,
            'on_chunk' => function ($chunk) use (&$chunkReceived) {
                $chunkReceived = $chunk;
            },
        ],
        $retryConfig,
        'GET',
        $mocks,
        $createStream
    )->await();

    expect($result)->toBeInstanceOf(StreamingResponse::class)
        ->and($chunkReceived)->toBe('streaming content')
    ;
});

test('executeWithMockRetry retries download on failure', function () {
    $executor = createRetryableExecutor();
    $mocks = [];
    $fileManager = new FileManager();

    // First attempt fails
    $mock1 = new MockedRequest('GET');
    $mock1->setUrlPattern('https://api.example.com/file.pdf');
    $mock1->setError('Network error');
    $mock1->setRetryable(true);
    $mocks[] = $mock1;

    // Second attempt succeeds
    $mock2 = new MockedRequest('GET');
    $mock2->setUrlPattern('https://api.example.com/file.pdf');
    $mock2->setBody('PDF content');
    $mocks[] = $mock2;

    $retryConfig = new RetryConfig(maxRetries: 3, baseDelay: 0.05);
    $destPath = $fileManager->createTempFile();

    $result = $executor->executeWithMockRetry(
        'https://api.example.com/file.pdf',
        [
            'method' => 'GET',
            'download' => $destPath,
        ],
        $retryConfig,
        'GET',
        $mocks,
        null,
        $fileManager
    )->await();

    expect($result['status'])->toBe(200)
        ->and(file_get_contents($result['file']))->toBe('PDF content')
    ;

    @unlink($result['file']);
});

test('throws exception when no mock found during retry', function () {
    $executor = createRetryableExecutor();
    $mocks = [];
    $retryConfig = new RetryConfig(maxRetries: 2);

    $executor->executeWithRetry(
        'https://api.example.com/nomock',
        [CURLOPT_CUSTOMREQUEST => 'GET'],
        $retryConfig,
        'GET',
        $mocks
    )->await();
})->throws(MockException::class);

test('records requests during retry attempts', function () {
    $executor = createRetryableExecutor();
    $mocks = [];

    // First two attempts fail
    for ($i = 0; $i < 2; $i++) {
        $mock = new MockedRequest('POST');
        $mock->setUrlPattern('https://api.example.com/create');
        $mock->setError('Timeout');
        $mock->setRetryable(true);
        $mocks[] = $mock;
    }

    // Third attempt succeeds
    $mock = new MockedRequest('POST');
    $mock->setUrlPattern('https://api.example.com/create');
    $mock->setStatusCode(201);
    $mock->setBody('{"created": true}');
    $mocks[] = $mock;

    $retryConfig = new RetryConfig(maxRetries: 3, baseDelay: 0.05);

    $result = $executor->executeWithRetry(
        'https://api.example.com/create',
        [CURLOPT_CUSTOMREQUEST => 'POST'],
        $retryConfig,
        'POST',
        $mocks
    )->await();

    expect($result->status())->toBe(201)
        ->and($result->body())->toBe('{"created": true}')
        ->and($mocks)->toBeEmpty()
    ;
});

test('executeWithMockRetry uses onChunk callback variant', function () {
    $executor = createRetryableExecutor();
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/stream');
    $mock->setBody('chunk data');
    $mocks[] = $mock;

    $retryConfig = new RetryConfig(maxRetries: 2);
    $chunkData = null;

    $result = $executor->executeWithMockRetry(
        'https://api.example.com/stream',
        [
            'method' => 'GET',
            'stream' => true,
            'onChunk' => function ($chunk) use (&$chunkData) {
                $chunkData = $chunk;
            },
        ],
        $retryConfig,
        'GET',
        $mocks
    )->await();

    expect($result)->toBeInstanceOf(StreamingResponse::class)
        ->and($chunkData)->toBe('chunk data')
    ;
});

test('executeWithMockRetry creates temp file when download path not specified', function () {
    $executor = createRetryableExecutor();
    $mocks = [];

    $mock = new MockedRequest('GET');
    $mock->setUrlPattern('https://api.example.com/download');
    $mock->setBody('downloaded content');
    $mocks[] = $mock;

    $retryConfig = new RetryConfig(maxRetries: 2);

    $result = $executor->executeWithMockRetry(
        'https://api.example.com/download',
        [
            'method' => 'GET',
            'download' => true,
        ],
        $retryConfig,
        'GET',
        $mocks
    )->await();

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('file')
        ->and(file_exists($result['file']))->toBeTrue()
        ->and(file_get_contents($result['file']))->toBe('downloaded content')
    ;

    @unlink($result['file']);
});

test('handles mixed curl and string options', function () {
    $executor = createRetryableExecutor();
    $mocks = [];

    $mock = new MockedRequest('POST');
    $mock->setUrlPattern('https://api.example.com/mixed');
    $mock->setBody('{"mixed": true}');
    $mocks[] = $mock;

    $retryConfig = new RetryConfig(maxRetries: 2);

    $result = $executor->executeWithMockRetry(
        'https://api.example.com/mixed',
        [
            'method' => 'POST',
            'body' => '{"test": "data"}',
            'headers' => ['Content-Type: application/json'],
        ],
        $retryConfig,
        'POST',
        $mocks
    )->await();

    expect($result->body())->toBe('{"mixed": true}');
});
