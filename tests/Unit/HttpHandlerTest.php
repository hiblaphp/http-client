<?php

declare(strict_types=1);

use Hibla\HttpClient\Handlers\HttpHandler;
use Hibla\HttpClient\Interfaces\Handler\RequestExecutorHandlerInterface;
use Hibla\HttpClient\Interfaces\Handler\RetryHandlerInterface;
use Hibla\HttpClient\Interfaces\Handler\StreamingHandlerInterface;
use Hibla\HttpClient\ValueObjects\RetryConfig;
use Hibla\Promise\Promise;

afterEach(function () {
    Mockery::close();
});

it('delegates stream calls to the StreamingHandler', function () {
    $streamingHandlerMock = Mockery::mock(StreamingHandlerInterface::class);

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
    $streamingHandlerMock = Mockery::mock(StreamingHandlerInterface::class);

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

it('delegates execution to the RequestExecutorHandler', function () {
    $requestExecutorMock = Mockery::mock(RequestExecutorHandlerInterface::class);

    $requestExecutorMock
        ->shouldReceive('execute')
        ->once()
        ->with('https://example.com/exec', Mockery::type('array'))
        ->andReturn(new Promise())
    ;

    $handler = new HttpHandler(null, $requestExecutorMock);

    $handler->sendRequest('https://example.com/exec', [CURLOPT_CUSTOMREQUEST => 'GET'], null);

    expect(true)->toBeTrue();
});

it('sends request without retry when no retry is configured', function () {
    $requestExecutorMock = Mockery::mock(RequestExecutorHandlerInterface::class);

    $requestExecutorMock
        ->shouldReceive('execute')
        ->once()
        ->with('https://example.com', [CURLOPT_CUSTOMREQUEST => 'POST'])
        ->andReturn(new Promise())
    ;

    $handler = new HttpHandler(null, $requestExecutorMock);
    $handler->sendRequest('https://example.com', [CURLOPT_CUSTOMREQUEST => 'POST'], null);

    expect(true)->toBeTrue();
});

it('sends request with retry when retry is configured', function () {
    $retryHandlerMock = Mockery::mock(RetryHandlerInterface::class);
    $retryConfig = new RetryConfig();

    $retryHandlerMock
        ->shouldReceive('execute')
        ->once()
        ->with('https://example.com', [CURLOPT_CUSTOMREQUEST => 'POST'], $retryConfig)
        ->andReturn(new Promise())
    ;

    $handler = new HttpHandler(null, null, $retryHandlerMock);
    $handler->sendRequest('https://example.com', [CURLOPT_CUSTOMREQUEST => 'POST'], $retryConfig);

    expect(true)->toBeTrue();
});

it('filters integer-only options for streaming handler', function () {
    $streamingHandlerMock = Mockery::mock(StreamingHandlerInterface::class);

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
    $streamingHandlerMock = Mockery::mock(StreamingHandlerInterface::class);

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
