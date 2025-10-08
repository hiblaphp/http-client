<?php

use Hibla\HttpClient\Stream;
use Hibla\HttpClient\Exceptions\HttpStreamException;

describe('Stream', function () {
    it('creates a stream from a resource', function () {
        $resource = fopen('php://temp', 'r+');
        $stream = new Stream($resource);

        expect($stream)->toBeInstanceOf(Stream::class)
            ->and($stream->isReadable())->toBeTrue()
            ->and($stream->isWritable())->toBeTrue();
    });

    it('creates a stream from string content', function () {
        $content = 'Hello, World!';
        $stream = Stream::fromString($content);

        expect($stream->getContents())->toBe($content);
    });

    it('throws exception when not a resource', function () {
        new Stream('not a resource');
    })->throws(HttpStreamException::class, 'Stream must be a resource');

    it('writes to a writable stream', function () {
        $resource = fopen('php://temp', 'r+');
        $stream = new Stream($resource);

        $written = $stream->write('test content');
        $stream->rewind();

        expect($written)->toBe(12)
            ->and($stream->getContents())->toBe('test content');
    });

    it('reads from a readable stream', function () {
        $stream = Stream::fromString('Hello, World!');

        expect($stream->read(5))->toBe('Hello')
            ->and($stream->read(2))->toBe(', ')
            ->and($stream->read(6))->toBe('World!');
    });

    it('checks if stream is at end', function () {
        $stream = Stream::fromString('test');
        $stream->getContents();

        expect($stream->eof())->toBeTrue();
    });

    it('gets stream size', function () {
        $content = 'Hello, World!';
        $stream = Stream::fromString($content);

        expect($stream->getSize())->toBe(strlen($content));
    });

    it('seeks to position in stream', function () {
        $stream = Stream::fromString('Hello, World!');
        $stream->seek(7);

        expect($stream->tell())->toBe(7)
            ->and($stream->read(5))->toBe('World');
    });

    it('rewinds stream to beginning', function () {
        $stream = Stream::fromString('Hello, World!');
        $stream->read(5);
        $stream->rewind();

        expect($stream->tell())->toBe(0)
            ->and($stream->read(5))->toBe('Hello');
    });

    it('converts stream to string', function () {
        $content = 'Hello, World!';
        $stream = Stream::fromString($content);

        expect((string) $stream)->toBe($content);
    });

    it('returns empty string when converting non-readable stream to string', function () {
        $resource = fopen('php://temp', 'w');
        $stream = new Stream($resource);

        expect((string) $stream)->toBe('');
    });

    it('detaches resource from stream', function () {
        $resource = fopen('php://temp', 'r+');
        $stream = new Stream($resource);

        $detached = $stream->detach();

        expect($detached)->toBe($resource)
            ->and($stream->getSize())->toBeNull()
            ->and($stream->isReadable())->toBeFalse()
            ->and($stream->isWritable())->toBeFalse()
            ->and($stream->isSeekable())->toBeFalse();

        fclose($detached);
    });

    it('returns null when detaching already detached stream', function () {
        $resource = fopen('php://temp', 'r+');
        $stream = new Stream($resource);

        $stream->detach();
        $result = $stream->detach();

        expect($result)->toBeNull();
    });

    it('closes stream', function () {
        $resource = fopen('php://temp', 'r+');
        $stream = new Stream($resource);
        $stream->close();

        expect($stream->eof())->toBeTrue();
    });

    it('throws exception when reading from non-readable stream', function () {
        $resource = fopen('php://output', 'w');
        $stream = new Stream($resource);

        $stream->read(10);
    })->throws(HttpStreamException::class, 'Cannot read from non-readable stream');

    it('throws exception when writing to non-writable stream', function () {
        $resource = fopen('php://temp', 'r');
        $stream = new Stream($resource);

        $stream->write('test');
    })->throws(HttpStreamException::class, 'Cannot write to a non-writable stream');

    it('throws exception when seeking non-seekable stream', function () {
        $resource = fopen('php://output', 'w');
        $stream = new Stream($resource);

        $stream->seek(10);
    })->throws(HttpStreamException::class, 'Stream is not seekable');

    it('throws exception with negative read length', function () {
        $stream = Stream::fromString('test');
        $stream->read(-1);
    })->throws(HttpStreamException::class, 'Length parameter cannot be negative');

    it('returns empty string when reading zero bytes', function () {
        $stream = Stream::fromString('test');
        expect($stream->read(0))->toBe('');
    });

    it('throws exception when reading from detached stream', function () {
        $stream = Stream::fromString('test');
        $stream->detach();

        $stream->read(5);
    })->throws(HttpStreamException::class, 'Stream is detached');

    it('throws exception when writing to detached stream', function () {
        $resource = fopen('php://temp', 'r+');
        $stream = new Stream($resource);
        $stream->detach();

        $stream->write('test');
    })->throws(HttpStreamException::class, 'Stream is detached');

    it('throws exception when seeking detached stream', function () {
        $stream = Stream::fromString('test');
        $stream->detach();

        $stream->seek(0);
    })->throws(HttpStreamException::class, 'Stream is detached');

    it('throws exception when getting position of detached stream', function () {
        $stream = Stream::fromString('test');
        $stream->detach();

        $stream->tell();
    })->throws(HttpStreamException::class, 'Stream is detached');

    it('throws exception when getting contents from detached stream', function () {
        $stream = Stream::fromString('test');
        $stream->detach();

        $stream->getContents();
    })->throws(HttpStreamException::class, 'Stream is detached');

    it('gets stream metadata', function () {
        $resource = fopen('php://temp', 'r+');
        $stream = new Stream($resource);

        $metadata = $stream->getMetadata();

        expect($metadata)->toBeArray()
            ->and($metadata['mode'])->toBe('w+b')
            ->and($metadata['seekable'])->toBeTrue();
    });

    it('gets specific metadata key', function () {
        $resource = fopen('php://temp', 'r+');
        $stream = new Stream($resource);

        expect($stream->getMetadata('mode'))->toBe('w+b')
            ->and($stream->getMetadata('seekable'))->toBeTrue();
    });

    it('returns null for non-existent metadata key', function () {
        $stream = Stream::fromString('test');

        expect($stream->getMetadata('non_existent_key'))->toBeNull();
    });

    it('returns empty array for metadata of detached stream', function () {
        $stream = Stream::fromString('test');
        $stream->detach();

        expect($stream->getMetadata())->toBe([]);
    });

    it('returns null for metadata key of detached stream', function () {
        $stream = Stream::fromString('test');
        $stream->detach();

        expect($stream->getMetadata('mode'))->toBeNull();
    });

    it('invalidates size cache after write', function () {
        $resource = fopen('php://temp', 'r+');
        $stream = new Stream($resource);

        $stream->write('initial');
        $initialSize = $stream->getSize();

        $stream->write(' additional');
        $newSize = $stream->getSize();

        expect($newSize)->toBeGreaterThan($initialSize);
    });
});
