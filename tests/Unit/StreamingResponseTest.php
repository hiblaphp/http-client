<?php

use Hibla\HttpClient\Stream;
use Hibla\HttpClient\StreamingResponse;

describe('StreamingResponse', function () {
    it('creates a streaming response', function () {
        $stream = Stream::fromString('response body');
        $response = new StreamingResponse($stream, 200, ['Content-Type' => 'text/plain']);

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getHeaderLine('Content-Type'))->toBe('text/plain');
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
            ->and($first)->toBe('test content');
    });

    it('parses JSON response', function () {
        $data = ['name' => 'John', 'age' => 30, 'active' => true];
        $stream = Stream::fromString(json_encode($data));
        $response = new StreamingResponse($stream, 200);

        expect($response->json())->toBe($data);
    });

    it('returns empty array for invalid JSON', function () {
        $stream = Stream::fromString('invalid json');
        $response = new StreamingResponse($stream, 200);

        expect($response->json())->toBe([]);
    });

    it('returns empty array for non-array JSON', function () {
        $stream = Stream::fromString('"just a string"');
        $response = new StreamingResponse($stream, 200);

        expect($response->json())->toBe([]);
    });

    it('saves stream to file', function () {
        $content = 'File content to save';
        $stream = Stream::fromString($content);
        $response = new StreamingResponse($stream, 200);

        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        $result = $response->saveToFile($tempFile);

        expect($result)->toBeTrue()
            ->and(file_get_contents($tempFile))->toBe($content);

        unlink($tempFile);
    });

    it('returns false when file cannot be opened', function () {
        $stream = Stream::fromString('test');
        $response = new StreamingResponse($stream, 200);

        $tempDir = sys_get_temp_dir() . '/test_dir_' . uniqid();
        mkdir($tempDir);

        set_error_handler(function () {});
        $result = $response->saveToFile($tempDir);
        restore_error_handler();

        expect($result)->toBeFalse();

        rmdir($tempDir);
    });

    it('saves large content to file in chunks', function () {
        $content = str_repeat('Large content chunk. ', 1000); // ~20KB
        $stream = Stream::fromString($content);
        $response = new StreamingResponse($stream, 200);

        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        $result = $response->saveToFile($tempFile);

        expect($result)->toBeTrue()
            ->and(file_get_contents($tempFile))->toBe($content);

        unlink($tempFile);
    });

    it('streams to file path', function () {
        $content = 'Stream content';
        $stream = Stream::fromString($content);
        $response = new StreamingResponse($stream, 200);

        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        $result = $response->streamTo($tempFile);

        expect($result)->toBeTrue()
            ->and(file_get_contents($tempFile))->toBe($content);

        unlink($tempFile);
    });

    it('streams to resource handle', function () {
        $content = 'Stream to resource handle';
        $stream = Stream::fromString($content);
        $response = new StreamingResponse($stream, 200);

        $destination = fopen('php://temp', 'r+');
        $result = $response->streamTo($destination);
        rewind($destination);

        expect($result)->toBeTrue()
            ->and(stream_get_contents($destination))->toBe($content);

        fclose($destination);
    });

    it('returns false for invalid stream destination type', function () {
        $stream = Stream::fromString('test');
        $response = new StreamingResponse($stream, 200);

        expect($response->streamTo(123))->toBeFalse()
            ->and($response->streamTo(['array']))->toBeFalse()
            ->and($response->streamTo(null))->toBeFalse();
    });

    it('handles empty stream', function () {
        $stream = Stream::fromString('');
        $response = new StreamingResponse($stream, 200);

        expect($response->body())->toBe('')
            ->and($response->json())->toBe([]);
    });

    it('gets underlying stream interface', function () {
        $stream = Stream::fromString('test');
        $response = new StreamingResponse($stream, 200);

        expect($response->getStream())->toBe($stream)
            ->and($response->getStream())->toBeInstanceOf(\Psr\Http\Message\StreamInterface::class);
    });

    it('rewinds seekable stream before saving to file', function () {
        $content = 'Rewind test';
        $stream = Stream::fromString($content);
        $response = new StreamingResponse($stream, 200);

        $stream->read(6);

        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        $response->saveToFile($tempFile);

        expect(file_get_contents($tempFile))->toBe($content);

        unlink($tempFile);
    });

    it('rewinds seekable stream before streaming to resource', function () {
        $content = 'Another rewind test';
        $stream = Stream::fromString($content);
        $response = new StreamingResponse($stream, 200);

        $stream->read(7);

        $destination = fopen('php://temp', 'r+');
        $response->streamTo($destination);
        rewind($destination);

        expect(stream_get_contents($destination))->toBe($content);

        fclose($destination);
    });

    it('handles stream errors gracefully when streaming to resource', function () {
        $stream = Stream::fromString('test');
        $response = new StreamingResponse($stream, 200);

        $destination = fopen('php://temp', 'r+');
        fclose($destination);

        $result = $response->streamTo($destination);

        expect($result)->toBeFalse();
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
            ->and($response->getHeaderLine('X-Custom-Header'))->toBe('custom-value');
    });
});
