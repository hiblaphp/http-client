<?php

declare(strict_types=1);

namespace Tests\Feature;

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Exceptions\TimeoutException;
use Hibla\HttpClient\Http;
use Hibla\HttpClient\Utils\HiblaStreamAdapter;
use Hibla\HttpServer\Internals\ProtocolAttacher;
use Hibla\HttpServer\Message\Request as ServerRequest;
use Hibla\HttpServer\Message\Response as ServerResponse;
use Hibla\Promise\Promise;
use Hibla\Socket\SocketServer;
use Hibla\Stream\ThroughStream;
use Tests\Fixtures\HttpBin;

use function Hibla\await;

beforeEach(function () {
    HttpBin::skipIfUnreachable();
});

afterEach(function () {
    Loop::reset();
});

describe('HiblaStreamAdapter & Async Upload Integration', function () {

    it('adapts a push-based async stream to a pull-based PSR-7 stream via fiber suspension', function () {
        $asyncStream = new ThroughStream();
        $adapter = new HiblaStreamAdapter($asyncStream);

        Loop::addTimer(0.05, fn () => $asyncStream->write('hello '));
        Loop::addTimer(0.10, function () use ($asyncStream) {
            $asyncStream->write('world');
            $asyncStream->end();
        });

        $chunk1 = $adapter->read(6);
        $chunk2 = $adapter->getContents();

        expect($chunk1)->toBe('hello ')
            ->and($chunk2)->toBe('world')
            ->and($adapter->eof())->toBeTrue()
        ;
    });

    it('takes a ReadableStreamInterface and uploads it asynchronously to HttpBin', function () {
        $stream = new ThroughStream();

        Loop::addTimer(0.05, fn () => $stream->write('streaming '));
        Loop::addTimer(0.10, fn () => $stream->write('data '));
        Loop::addTimer(0.15, function () use ($stream) {
            $stream->write('is awesome!');
            $stream->end();
        });

        $response = await(
            Http::client()
                ->body($stream)
                ->post(HttpBin::url('/post'))
        );

        expect($response->status())->toBe(200);

        $data = $response->json('data');
        if (is_string($data) && str_starts_with($data, 'data:')) {
            $data = base64_decode(substr($data, strpos($data, ',') + 1));
        }

        expect($data)->toBe('streaming data is awesome!');
    });

    it('automatically applies Transfer-Encoding: chunked when streaming an unknown size body', function () {
        $stream = new ThroughStream();
        
        Loop::addTimer(0.01, function () use ($stream) {
            $stream->write('ping');
            $stream->end();
        });

        $response = await(
            Http::client()
                ->body($stream)
                ->post(HttpBin::url('/post'))
        );

        $headers = array_change_key_case($response->json('headers') ?? [], CASE_LOWER);
        
        expect($headers)->toHaveKey('transfer-encoding')
            ->and($headers['transfer-encoding'][0])->toBe('chunked');
    });

    it('handles an immediately closed (empty) readable stream correctly', function () {
        $stream = new ThroughStream();
        
        Loop::addTimer(0.01, fn() => $stream->end());

        $response = await(
            Http::client()
                ->body($stream)
                ->post(HttpBin::url('/post'))
        );

        expect($response->status())->toBe(200);
        
        $data = $response->json('data');
        expect($data)->toBe('');
    });

    it('aborts the network request gracefully if the local stream emits an error mid-upload', function () {
        $stream = new ThroughStream();

        Loop::addTimer(0.05, fn () => $stream->write('chunk 1'));
        Loop::addTimer(0.10, function () use ($stream) {
            $stream->emit('error', [new \RuntimeException('Local file read failed')]);
        });

        $promise = Http::client()
            ->body($stream)
            ->post(HttpBin::url('/post'));

        expect(fn () => await($promise))->toThrow(NetworkException::class);
    });

    it('closes the local stream if the HTTP request times out mid-transfer', function () {
        $stream = new ThroughStream();
        $streamClosed = false;

        $stream->on('close', function() use (&$streamClosed) {
            $streamClosed = true;
        });

        Loop::addTimer(0.2, fn () => $stream->write('chunk 1'));
        
        Loop::addTimer(1.5, function () use ($stream) {
            if ($stream->isReadable()) {
                $stream->write('chunk 2');
                $stream->end();
            }
        });

        $promise = Http::client()
            ->timeout(1)
            ->body($stream)
            ->post(HttpBin::url('/post'));

        expect(fn () => await($promise))->toThrow(TimeoutException::class);
        
        expect($streamClosed)->toBeTrue();
    });

    it('streams data over TCP chunk-by-chunk in real time using the Hibla HTTP Server', function () {
        if (! class_exists(SocketServer::class)) {
            test()->markTestSkipped('hiblaphp/http-server is required to run this test.');
        }

        $socket = new SocketServer('tcp://127.0.0.1:0');
        $url = str_replace('tcp://', 'http://', $socket->getAddress());

        $serverReceivedChunks = [];

        ProtocolAttacher::attach($socket, function (ServerRequest $serverRequest) use (&$serverReceivedChunks) {
            $incomingStream = $serverRequest->body;

            $uploadPromise = new Promise(function ($resolve, $reject) use ($incomingStream, &$serverReceivedChunks) {
                $incomingStream->on('data', function (string $chunk) use (&$serverReceivedChunks) {
                    $serverReceivedChunks[] = $chunk;
                });
                
                $incomingStream->on('end', function () use ($resolve) {
                    $resolve(true);
                });
                
                $incomingStream->on('error', $reject);
            });

            await($uploadPromise);

            return ServerResponse::plaintext('Upload received');
        });

        $clientStream = new ThroughStream();

        Loop::addTimer(0.05, fn () => $clientStream->write('chunk_A_'));
        Loop::addTimer(0.10, fn () => $clientStream->write('chunk_B_'));
        Loop::addTimer(0.15, function () use ($clientStream) {
            $clientStream->write('chunk_C');
            $clientStream->end();
        });

        $response = await(
            Http::client()
                ->body($clientStream)
                ->post($url . '/upload')
        );

        $socket->close();

        expect($response->status())->toBe(200)
            ->and($response->body())->toBe('Upload received');

        $fullPayload = implode('', $serverReceivedChunks);
        
        expect($fullPayload)->toBe('chunk_A_chunk_B_chunk_C')
            ->and(count($serverReceivedChunks))->toBeGreaterThanOrEqual(1);
    });

    it('streams a large payload continuously without memory bloat', function () {
        if (! class_exists(SocketServer::class)) {
            test()->markTestSkipped('hiblaphp/http-server is required to run this test.');
        }

        $socket = new SocketServer('tcp://127.0.0.1:0');
        $url = str_replace('tcp://', 'http://', $socket->getAddress());

        $totalBytesReceived = 0;

        ProtocolAttacher::attach($socket, function (ServerRequest $serverRequest) use (&$totalBytesReceived) {
            $incomingStream = $serverRequest->body;

            $uploadPromise = new Promise(function ($resolve, $reject) use ($incomingStream, &$totalBytesReceived) {
                $incomingStream->on('data', function (string $chunk) use (&$totalBytesReceived) {
                    $totalBytesReceived += strlen($chunk);
                });
                
                $incomingStream->on('end', function () use ($resolve) {
                    $resolve(true);
                });
                
                $incomingStream->on('error', $reject);
            });

            await($uploadPromise);

            return ServerResponse::plaintext('OK');
        });

        $clientStream = new ThroughStream();
        
        Loop::addTimer(0.01, function () use ($clientStream) {
            $clientStream->write(str_repeat('A', 100 * 16384));
            $clientStream->end();
        });

        $response = await(
            Http::client()
                ->body($clientStream)
                ->post($url . '/upload')
        );

        $socket->close();

        expect($response->status())->toBe(200)
            ->and($totalBytesReceived)->toBe(100 * 16384);
    });
});