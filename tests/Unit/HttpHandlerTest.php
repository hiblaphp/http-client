<?php

use Hibla\HttpClient\Handlers\FetchHandler;
use Hibla\HttpClient\Handlers\HttpHandler;
use Hibla\HttpClient\Handlers\RequestExecutorHandler;
use Hibla\HttpClient\Handlers\RetryHandler;
use Hibla\HttpClient\Handlers\StreamingHandler;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\RetryConfig;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Promise;

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

it('sends request without retry when no retry is configured', function() {
    $requestExecutorMock = Mockery::mock(RequestExecutorHandler::class);
    
    $requestExecutorMock
        ->shouldReceive('execute')
        ->once()
        ->with('https://example.com', [CURLOPT_CUSTOMREQUEST => 'POST'])
        ->andReturn(Promise::resolved(new Response('', 200, [])));
        
    $handler = new HttpHandler(null, null, $requestExecutorMock);
    $handler->sendRequest('https://example.com', [CURLOPT_CUSTOMREQUEST => 'POST'], null, null);

    expect(true)->toBeTrue();
});

it('sends request with retry when retry is configured', function() {
    $retryHandlerMock = Mockery::mock(RetryHandler::class);
    $retryConfig = new RetryConfig();
    
    $retryHandlerMock
        ->shouldReceive('execute')
        ->once()
        ->with('https://example.com', [CURLOPT_CUSTOMREQUEST => 'POST'], $retryConfig)
        ->andReturn(Promise::resolved(new Response('', 200, [])));
    
    $handler = new HttpHandler(null, null, null, $retryHandlerMock);
    $handler->sendRequest('https://example.com', [CURLOPT_CUSTOMREQUEST => 'POST'], null, $retryConfig);

    expect(true)->toBeTrue();
});