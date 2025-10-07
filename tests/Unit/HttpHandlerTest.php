<?php

use Hibla\HttpClient\Handlers\FetchHandler;
use Hibla\HttpClient\Handlers\HttpHandler;
use Hibla\HttpClient\Handlers\StreamingHandler;
use Hibla\HttpClient\RetryConfig;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Promise;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

afterEach(function () {
    Mockery::close();
});

it('delegates stream calls to the StreamingHandler', function () {
    $streamingHandlerMock = Mockery::mock(StreamingHandler::class);
    $fetchHandlerMock = Mockery::mock(FetchHandler::class);

    $fetchHandlerMock
        ->shouldReceive('normalizeFetchOptions')
        ->once()
        ->andReturn([]);

    $streamingHandlerMock
        ->shouldReceive('streamRequest')
        ->once()
        ->with('https://example.com/stream', Mockery::type('array'), null)
        ->andReturn(new CancellablePromise());


    $handler = new HttpHandler($streamingHandlerMock, $fetchHandlerMock);
    $handler->stream('https://example.com/stream');
    
    expect(true)->toBeTrue();
});

it('delegates download calls to the StreamingHandler', function () {
    $streamingHandlerMock = Mockery::mock(StreamingHandler::class);
    $fetchHandlerMock = Mockery::mock(FetchHandler::class);

    $fetchHandlerMock
        ->shouldReceive('normalizeFetchOptions')
        ->once()
        ->andReturn([]);

    $streamingHandlerMock
        ->shouldReceive('downloadFile')
        ->once()
        ->with('https://example.com/file.zip', '/tmp/file.zip', Mockery::type('array'))
        ->andReturn(new CancellablePromise());

    $handler = new HttpHandler($streamingHandlerMock, $fetchHandlerMock);
    $handler->download('https://example.com/file.zip', '/tmp/file.zip');

    expect(true)->toBeTrue();
});

it('delegates fetch calls to the FetchHandler', function () {
    $streamingHandlerMock = Mockery::mock(StreamingHandler::class);
    $fetchHandlerMock = Mockery::mock(FetchHandler::class);

    $fetchHandlerMock
        ->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/fetch', ['method' => 'GET'])
        ->andReturn(new Promise());

    $handler = new HttpHandler($streamingHandlerMock, $fetchHandlerMock);
    $handler->fetch('https://example.com/fetch', ['method' => 'GET']);

    expect(true)->toBeTrue();
});

it('dispatches request to executeBasicFetch when no retry is configured', function() {
    $streamingHandlerMock = Mockery::mock(StreamingHandler::class);
    $fetchHandlerMock = Mockery::mock(FetchHandler::class);
    
    $fetchHandlerMock
        ->shouldReceive('executeBasicFetch')
        ->once()
        ->with('https://example.com', Mockery::type('array'))
        ->andReturn(new Promise());
    
    $fetchHandlerMock->shouldNotReceive('fetchWithRetry');
        
    $handler = new HttpHandler($streamingHandlerMock, $fetchHandlerMock);
    
    $reflection = new ReflectionClass(HttpHandler::class);
    $method = $reflection->getMethod('dispatchRequest');
    $method->invoke($handler, 'https://example.com', [], null);

    expect(true)->toBeTrue();
});

it('dispatches request to fetchWithRetry when retry is configured', function() {
    $streamingHandlerMock = Mockery::mock(StreamingHandler::class);
    $fetchHandlerMock = Mockery::mock(FetchHandler::class);
    $retryConfig = new RetryConfig();
    
    $fetchHandlerMock
        ->shouldReceive('fetchWithRetry')
        ->once()
        ->with('https://example.com', Mockery::type('array'), $retryConfig)
        ->andReturn(new Promise());

    $fetchHandlerMock->shouldNotReceive('executeBasicFetch');
    
    $handler = new HttpHandler($streamingHandlerMock, $fetchHandlerMock);
    
    $reflection = new ReflectionClass(HttpHandler::class);
    $method = $reflection->getMethod('dispatchRequest');
    $method->invoke($handler, 'https://example.com', [], $retryConfig);

    expect(true)->toBeTrue();
});