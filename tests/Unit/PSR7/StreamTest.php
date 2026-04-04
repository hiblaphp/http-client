<?php

declare(strict_types=1);

use Hibla\HttpClient\Exceptions\HttpStreamException;
use Hibla\HttpClient\Stream;

use function Hibla\await;

describe('Stream', function () {
    it('creates a stream from a resource', function () {
        $resource = fopen('php://temp', 'r+');
        $stream = new Stream($resource);

        expect($stream)->toBeInstanceOf(Stream::class)
            ->and($stream->isReadable())->toBeTrue()
            ->and($stream->isWritable())->toBeTrue()
        ;
    });

    it('creates a stream from string content', function () {
        $content = 'Hello, World!';
        $stream = Stream::fromString($content);

        expect($stream->getContents())->toBe($content);
    });

    it('throws exception when not a resource', function () {
        new Stream('not a resource');
    })->throws(HttpStreamException::class, 'Unable to create or use temporary stream');

    it('writes to a writable stream', function () {
        $resource = fopen('php://temp', 'r+');
        $stream = new Stream($resource);

        $written = $stream->write('test content');
        $stream->rewind();

        expect($written)->toBe(12)
            ->and($stream->getContents())->toBe('test content')
        ;
    });

    it('reads from a readable stream', function () {
        $stream = Stream::fromString('Hello, World!');

        expect($stream->read(5))->toBe('Hello')
            ->and($stream->read(2))->toBe(', ')
            ->and($stream->read(6))->toBe('World!')
        ;
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
            ->and($stream->read(5))->toBe('World')
        ;
    });

    it('rewinds stream to beginning', function () {
        $stream = Stream::fromString('Hello, World!');
        $stream->read(5);
        $stream->rewind();

        expect($stream->tell())->toBe(0)
            ->and($stream->read(5))->toBe('Hello')
        ;
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
            ->and($stream->isSeekable())->toBeFalse()
        ;

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
    })->throws(HttpStreamException::class, 'Unable to seek to position 10');

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
            ->and($metadata['seekable'])->toBeTrue()
        ;
    });

    it('gets specific metadata key', function () {
        $resource = fopen('php://temp', 'r+');
        $stream = new Stream($resource);

        expect($stream->getMetadata('mode'))->toBe('w+b')
            ->and($stream->getMetadata('seekable'))->toBeTrue()
        ;
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

    describe('readAsync', function () {
        it('resolves with data when written before enqueue', function () {
            $stream = new Stream();
            $stream->write('hello');
            $stream->getHandler()->markEof();

            expect(await($stream->readAsync(5)))->toBe('hello');
        });

        it('resolves with data pushed after enqueue', function () {
            $stream = new Stream();
            $promise = $stream->readAsync(5);
            $stream->write('world');

            expect(await($promise))->toBe('world');
        });

        it('resolves with null at EOF when no data remains', function () {
            $stream = new Stream();
            $promise = $stream->readAsync(10);
            $stream->getHandler()->markEof();

            expect(await($promise))->toBeNull();
        });

        it('reads up to the requested length', function () {
            $stream = new Stream();
            $stream->write('hello world');
            $stream->getHandler()->markEof();

            expect(await($stream->readAsync(5)))->toBe('hello');
        });

        it('reads whatever is available when less than requested', function () {
            $stream = new Stream();
            $stream->write('hi');
            $stream->getHandler()->markEof();

            $result = await($stream->readAsync(100));
            expect(strlen($result))->toBe(2);
        });

        it('resolves sequentially across multiple reads', function () {
            $stream = new Stream();
            $p1 = $stream->readAsync(5);
            $p2 = $stream->readAsync(5);

            $stream->write('hello');
            expect(await($p1))->toBe('hello');

            $stream->write('world');
            expect(await($p2))->toBe('world');
        });

        it('rejects when stream is closed mid-read', function () {
            $stream = new Stream();
            $promise = $stream->readAsync(10);
            $stream->close();

            expect(fn () => await($promise))->toThrow(RuntimeException::class, 'Stream closed');
        });

        it('cancellation removes promise from queue so next read gets the data', function () {
            $stream = new Stream();
            $p1 = $stream->readAsync(5);
            $p2 = $stream->readAsync(5);

            $p1->cancel();
            $stream->write('world');

            expect(await($p2))->toBe('world');
        });

        it('cancelled promise does not resolve or reject after cancellation', function () {
            $stream = new Stream();
            $promise = $stream->readAsync(5);

            $promise->cancel();

            expect($promise->isCancelled())->toBeTrue();
        });

        it('uses default chunk size of 65536 when no length given', function () {
            $stream = new Stream();
            $data = str_repeat('a', 100);
            $stream->write($data);
            $stream->getHandler()->markEof();

            $result = await($stream->readAsync());
            expect(strlen($result))->toBe(100);
        });
    });

    describe('readLineAsync', function () {
        it('reads a single line terminated by newline', function () {
            $stream = Stream::fromString("line one\nline two\n");

            expect(await($stream->readLineAsync()))->toBe("line one\n");
        });

        it('reads multiple lines sequentially', function () {
            $stream = Stream::fromString("first\nsecond\nthird\n");

            expect(await($stream->readLineAsync()))->toBe("first\n");
            expect(await($stream->readLineAsync()))->toBe("second\n");
            expect(await($stream->readLineAsync()))->toBe("third\n");
        });

        it('returns last line without trailing newline', function () {
            $stream = Stream::fromString('only line');

            expect(await($stream->readLineAsync()))->toBe('only line');
        });

        it('returns null at EOF', function () {
            $stream = Stream::fromString('');

            expect(await($stream->readLineAsync()))->toBeNull();
        });

        it('resolves with line when newline arrives asynchronously', function () {
            $stream = new Stream();
            $promise = $stream->readLineAsync();

            $stream->write("async line\n");

            expect(await($promise))->toBe("async line\n");
        });

        it('handles CRLF line endings', function () {
            $stream = Stream::fromString("line one\r\nline two\r\n");

            expect(await($stream->readLineAsync()))->toBe("line one\r\n");
            expect(await($stream->readLineAsync()))->toBe("line two\r\n");
        });

        it('rejects when stream is closed', function () {
            $stream = new Stream();
            $stream->close();

            expect(fn () => await($stream->readLineAsync()))
                ->toThrow(HttpStreamException::class, 'Stream is closed')
            ;
        });

        it('reads full line even when maxLength is smaller than line length', function () {
            $stream = Stream::fromString("hello world\n");

            $result = await($stream->readLineAsync(5));
            expect($result)->toBe("hello world\n");
        });

        it('correctly reads all lines when multiple lines arrive in a single chunk', function () {
            $stream = Stream::fromString("line one\nline two\nline three\nline four\nline five\n");

            expect(await($stream->readLineAsync()))->toBe("line one\n");
            expect(await($stream->readLineAsync()))->toBe("line two\n");
            expect(await($stream->readLineAsync()))->toBe("line three\n");
            expect(await($stream->readLineAsync()))->toBe("line four\n");
            expect(await($stream->readLineAsync()))->toBe("line five\n");
            expect(await($stream->readLineAsync()))->toBeNull();
        });

        it('does not duplicate content when lines span multiple async writes', function () {
            $stream = new Stream();

            $promise1 = $stream->readLineAsync();
            $stream->write("first\n");
            expect(await($promise1))->toBe("first\n");

            $promise2 = $stream->readLineAsync();
            $stream->write("second\n");
            expect(await($promise2))->toBe("second\n");

            $promise3 = $stream->readLineAsync();
            $stream->write("third\n");
            expect(await($promise3))->toBe("third\n");

            $promise4 = $stream->readLineAsync();
            $stream->write("fourth\n");
            $result = await($promise4);
            expect($result)->toBe("fourth\n");
            expect($result)->not->toContain('third');
        });
    });

    describe('readAllAsync', function () {
        it('reads all content from a fromString stream', function () {
            $stream = Stream::fromString('full content');

            expect(await($stream->readAllAsync()))->toBe('full content');
        });

        it('reads content written in multiple chunks', function () {
            $stream = new Stream();
            $stream->write('chunk one ');
            $stream->write('chunk two');
            $stream->getHandler()->markEof();

            expect(await($stream->readAllAsync()))->toBe('chunk one chunk two');
        });

        it('returns empty string on empty EOF stream', function () {
            $stream = new Stream();
            $stream->getHandler()->markEof();

            expect(await($stream->readAllAsync()))->toBe('');
        });

        it('rejects when stream is closed', function () {
            $stream = new Stream();
            $stream->close();

            expect(fn () => await($stream->readAllAsync()))
                ->toThrow(HttpStreamException::class, 'Stream is closed')
            ;
        });

        it('respects maxLength and stops reading at the limit', function () {
            $stream = Stream::fromString(str_repeat('a', 200));

            $result = await($stream->readAllAsync(100));
            expect(strlen($result))->toBeLessThanOrEqual(100);
        });

        it('includes prepend buffer content in result', function () {
            $stream = new Stream();
            $stream->getHandler()->setPrependBuffer('pre_');
            $stream->write('data');
            $stream->getHandler()->markEof();

            expect(await($stream->readAllAsync()))->toBe('pre_data');
        });
    });
});
