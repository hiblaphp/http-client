<?php

declare(strict_types=1);

use Hibla\HttpClient\Handlers\Curl\FetchHandler;
use Hibla\HttpClient\Handlers\Curl\RequestExecutorHandler;
use Hibla\HttpClient\Handlers\Curl\RetryHandler;
use Hibla\HttpClient\Handlers\Curl\StreamingHandler;
use Hibla\HttpClient\Handlers\HttpHandler;
use Hibla\HttpClient\ValueObjects\RetryConfig;
use Hibla\Promise\Promise;

afterEach(function () {
    Mockery::close();
});

it('delegates stream calls to the StreamingHandler', function () {
    $streamingHandlerMock = Mockery::mock(StreamingHandler::class);

    $streamingHandlerMock
        ->shouldReceive('streamRequest')
        ->once()
        ->with('https://example.com/stream', Mockery::type('array'), null)
        ->andReturn(new Promise())
    ;

    $handler = new HttpHandler($streamingHandlerMock);

    $handler->stream('https://example.com/stream', [], null);

    expect(true)->toBeTrue();
});

it('delegates download calls to the StreamingHandler', function () {
    $streamingHandlerMock = Mockery::mock(StreamingHandler::class);

    $streamingHandlerMock
        ->shouldReceive('downloadFile')
        ->once()
        ->with('https://example.com/file.zip', '/tmp/file.zip', Mockery::type('array'))
        ->andReturn(new Promise())
    ;

    $handler = new HttpHandler($streamingHandlerMock);

    $handler->download('https://example.com/file.zip', '/tmp/file.zip', []);

    expect(true)->toBeTrue();
});

it('delegates fetch calls to the FetchHandler', function () {
    $fetchHandlerMock = Mockery::mock(FetchHandler::class);

    $fetchHandlerMock
        ->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/fetch', ['method' => 'GET'])
        ->andReturn(new Promise())
    ;

    $handler = new HttpHandler(null, $fetchHandlerMock);
    $handler->fetch('https://example.com/fetch', ['method' => 'GET']);

    expect(true)->toBeTrue();
});

it('sends request without retry when no retry is configured', function () {
    $requestExecutorMock = Mockery::mock(RequestExecutorHandler::class);

    $requestExecutorMock
        ->shouldReceive('execute')
        ->once()
        ->with('https://example.com', [CURLOPT_CUSTOMREQUEST => 'POST'])
        ->andReturn(new Promise())
    ;

    $handler = new HttpHandler(null, null, $requestExecutorMock);
    $handler->sendRequest('https://example.com', [CURLOPT_CUSTOMREQUEST => 'POST'], null);

    expect(true)->toBeTrue();
});

it('sends request with retry when retry is configured', function () {
    $retryHandlerMock = Mockery::mock(RetryHandler::class);
    $retryConfig = new RetryConfig();

    $retryHandlerMock
        ->shouldReceive('execute')
        ->once()
        ->with('https://example.com', [CURLOPT_CUSTOMREQUEST => 'POST'], $retryConfig)
        ->andReturn(new Promise())
    ;

    $handler = new HttpHandler(null, null, null, $retryHandlerMock);
    $handler->sendRequest('https://example.com', [CURLOPT_CUSTOMREQUEST => 'POST'], $retryConfig);

    expect(true)->toBeTrue();
});

it('filters integer-only options for streaming handler', function () {
    $streamingHandlerMock = Mockery::mock(StreamingHandler::class);

    $options = [
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        '_cookie_jar' => 'something',
        'retry' => 'config',
    ];

    $streamingHandlerMock
        ->shouldReceive('streamRequest')
        ->once()
        ->with(
            'https://example.com/stream',
            Mockery::on(function ($arg) {
                // Verify only integer keys are passed
                foreach (array_keys($arg) as $key) {
                    if (! is_int($key)) {
                        return false;
                    }
                }

                return true;
            }),
            null
        )
        ->andReturn(new Promise())
    ;

    $handler = new HttpHandler($streamingHandlerMock);
    $handler->stream('https://example.com/stream', $options, null);

    expect(true)->toBeTrue();
});

it('filters integer-only options for download handler', function () {
    $streamingHandlerMock = Mockery::mock(StreamingHandler::class);

    $options = [
        CURLOPT_TIMEOUT => 30,
        '_destination' => '/tmp/file',
    ];

    $streamingHandlerMock
        ->shouldReceive('downloadFile')
        ->once()
        ->with(
            'https://example.com/file.zip',
            '/tmp/file.zip',
            Mockery::on(function ($arg) {
                foreach (array_keys($arg) as $key) {
                    if (! is_int($key)) {
                        return false;
                    }
                }

                return true;
            })
        )
        ->andReturn(new Promise())
    ;

    $handler = new HttpHandler($streamingHandlerMock);
    $handler->download('https://example.com/file.zip', '/tmp/file.zip', $options);

    expect(true)->toBeTrue();
});
