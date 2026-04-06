<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Stream;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

beforeEach(function () {
    Http::startTesting();
});

afterEach(function () {
    Http::stopTesting();
});

describe('Mixed Multipart Payload Edge Cases', function () {
    test('it captures multipart data from various source types', function () {
        Http::mock('POST')->url('/upload-types')->respondWithStatus(200)->register();

        $tempFile = Http::getTestingHandler()->createTempFile('disk.txt', 'file on disk');
        
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, 'resource content');
        rewind($resource);

        $stream = Stream::fromString('stream content');

        Http::request()
            ->withMultipart([
                'raw_text' => 'plain string',
                'json_data' => ['nested' => true]
            ])
            ->withFile('from_disk', $tempFile, 'disk.txt')
            ->withFile('from_resource', $resource, 'res.txt', 'text/plain')
            ->withFile('from_stream', $stream, 'stream.txt')
            ->post('/upload-types')
            ->wait();

        $payload = Http::getLastRequest()->getJson();

        expect($payload['raw_text'])->toBe('plain string');
        expect($payload['json_data'])->toBe('{"nested":true}');

        expect($payload['from_disk'])->toContain('[File: disk.txt | MIME: text/plain]');
        expect($payload['from_resource'])->toContain('[File: res.txt | MIME: text/plain]');
        expect($payload['from_stream'])->toContain('[File: stream.txt | MIME: application/octet-stream]');
    });

    test('it handles empty file uploads and missing mime types', function () {
        Http::mock('POST')->url('/empty-parts')->respondWithStatus(200)->register();
        $emptyFile = Http::getTestingHandler()->createTempFile('empty.bin', '');

        Http::request()
            ->withFile('empty_file', $emptyFile)
            ->withMultipart(['empty_string' => ''])
            ->post('/empty-parts')
            ->wait();

        $payload = Http::getLastRequest()->getJson();
        expect($payload['empty_string'])->toBe('')
            ->and($payload['empty_file'])->toContain('MIME: application/octet-stream');
    });

    test('it throws an exception when attaching a non-existent file', function () {
        expect(fn () => Http::request()->withFile('bad', '/does/not/exist/file.txt'))
            ->toThrow(InvalidArgumentException::class, 'File must be a file path, UploadedFileInterface, StreamInterface, or resource.');
    });

    test('it throws an exception when attaching an invalid variable type as file', function () {
        expect(fn () => Http::request()->withFile('invalid', 12345))
            ->toThrow(InvalidArgumentException::class);
    });

    test('it overwrites keys when mixing withFile and withMultipart', function () {
        Http::mock('POST')->url('/overwrite')->respondWithStatus(200)->register();
        $tempFile = Http::getTestingHandler()->createTempFile('test.txt', 'content');

        Http::request()
            ->withFile('document', $tempFile)
            ->withMultipart(['document' => 'overwritten text']) 
            ->post('/overwrite')
            ->wait();

        $payload = Http::getLastRequest()->getJson();
        expect($payload['document'])->toBe('overwritten text');
    });

    test('it supports the withFiles array helper with detailed configurations', function () {
        Http::mock('POST')->url('/with-files')->respondWithStatus(200)->register();
        
        $file1 = Http::getTestingHandler()->createTempFile('f1.txt', 'one');
        $file2 = Http::getTestingHandler()->createTempFile('f2.csv', 'two');

        Http::request()
            ->withFiles([
                'simple' => $file1,
                'complex' => ['path' => $file2, 'name' => 'custom.csv', 'type' => 'text/csv']
            ])
            ->post('/with-files')
            ->wait();

        $payload = Http::getLastRequest()->getJson();
        expect($payload['simple'])->toContain('[File: f1.txt | MIME: text/plain]')
            ->and($payload['complex'])->toContain('[File: custom.csv | MIME: text/csv]');
    });

    test('it accepts UploadedFileInterface implementations', function () {
        Http::mock('POST')->url('/uploaded-file')->respondWithStatus(200)->register();
    
        $uploadedFile = new class implements UploadedFileInterface {
            public function getStream(): StreamInterface { 
                return Stream::fromString('mock content'); 
            }
            public function moveTo(string $targetPath): void {}
            public function getSize(): ?int { return 12; }
            public function getError(): int { return 0; }
            public function getClientFilename(): ?string { return 'upload.pdf'; }
            public function getClientMediaType(): ?string { return 'application/pdf'; }
        };

        Http::request()
            ->withFile('resume', $uploadedFile)
            ->post('/uploaded-file')
            ->wait();

        $payload = Http::getLastRequest()->getJson();
        expect($payload['resume'])->toContain('[File: upload.pdf | MIME: application/pdf]');
    });
});