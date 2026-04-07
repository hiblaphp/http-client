<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Http;
use Hibla\HttpClient\StreamingResponse;
use Tests\Fixtures\HttpBin;

use function Hibla\await;

describe('Streaming Integration (Push and Pull)', function () {
    
    beforeEach(function () {
        HttpBin::skipIfUnreachable();
    });

    describe('Push Approach (Callbacks)', function () {
        
        it('streams multiple JSON objects and triggers the chunk callback', function () {
            $chunksReceived = 0;
            $accumulatedData = '';

            $response = await(
                Http::request()->stream(
                    HttpBin::url('/stream/3'), 
                    function (string $chunk) use (&$chunksReceived, &$accumulatedData) {
                        $chunksReceived++;
                        $accumulatedData .= $chunk;
                    }
                )
            );

            expect($response)->toBeInstanceOf(StreamingResponse::class);
            expect($response->status())->toBe(200);

            $lines = array_filter(explode("\n", trim($accumulatedData)));
            
            expect(count($lines))->toBe(3);
            expect($chunksReceived)->toBeGreaterThan(0);
            
            $firstObject = json_decode($lines[0], true);
            expect($firstObject)->toHaveKey('id');
        });

        it('streams binary data accurately', function () {
            $totalBytesReceived = 0;

            $response = await(
                Http::request()->stream(
                    HttpBin::url('/stream-bytes/8192'), 
                    function (string $chunk) use (&$totalBytesReceived) {
                        $totalBytesReceived += strlen($chunk);
                    }
                )
            );

            expect($response->status())->toBe(200);
            expect($totalBytesReceived)->toBe(8192);
        });

        it('allows reading the full body from the StreamingResponse if no callback is provided', function () {
            $response = await(Http::request()->stream(HttpBin::url('/stream/2')));

            expect($response)->toBeInstanceOf(StreamingResponse::class);
            
            $body = $response->body();
            $lines = array_filter(explode("\n", trim($body)));
            
            expect(count($lines))->toBe(2);
            expect($body)->toContain('"id"');
        });

        it('streams the response of a POST request', function () {
            $accumulatedData = '';

            $response = await(
                Http::request()
                    ->withJson(['streaming' => 'works'])
                    ->withMethod('POST')
                    ->stream(
                        HttpBin::url('/post'), 
                        function (string $chunk) use (&$accumulatedData) {
                            $accumulatedData .= $chunk;
                        }
                    )
            );

            expect($response->status())->toBe(200);
            
            $decoded = json_decode($accumulatedData, true);
            expect($decoded['json']['streaming'])->toBe('works');
        });

        it('streams the response of a DELETE request', function () {
            $response = await(
                Http::request()
                    ->withMethod('DELETE')
                    ->stream(HttpBin::url('/delete'), function() {})
            );
            
            expect($response->status())->toBe(200);
            expect($response->json('url'))->toContain('/delete');
        });

        it('handles non-200 status codes gracefully during streaming', function () {
            $response = await(
                Http::request()->stream(HttpBin::url('/status/404'), function () {})
            );

            expect($response->status())->toBe(404);
            expect($response->failed())->toBeTrue();
            expect($response->clientError())->toBeTrue();
        });

        it('can be cancelled mid-stream', function () {
            $promise = Http::request()->stream(
                HttpBin::url('/delay/3'), 
                function (string $chunk) {}
            );

            Loop::addTimer(0.5, function () use ($promise) {
                $promise->cancel();
            });

            $exceptionThrown = false;
            try {
                $promise->wait(); 
            } catch (\Throwable $e) {
                $exceptionThrown = true;
            }

            expect($promise->isCancelled())->toBeTrue();
            expect($exceptionThrown)->toBeTrue();
        });

        it('bubbles up exceptions thrown inside the chunk callback and aborts the request', function () {
            $promise = Http::request()->stream(
                HttpBin::url('/stream/3'),
                function (string $chunk) {
                    throw new \RuntimeException('User callback failed');
                }
            );

            expect(fn() => $promise->wait())->toThrow(\RuntimeException::class, 'User callback failed');
        });
    });

    describe('Pull Approach (Async Stream API)', function () {
        
        it('pulls data chunk by chunk using readAsync()', function () {
            $response = await(Http::request()->stream(HttpBin::url('/stream-bytes/1024')));
            
            expect($response)->toBeInstanceOf(StreamingResponse::class);
            expect($response->status())->toBe(200);

            $totalBytes = 0;
            $chunksPulled = 0;

            while (true) {
                $chunk = await($response->readAsync(256));
                
                if ($chunk === null) {
                    break; 
                }

                $totalBytes += strlen($chunk);
                $chunksPulled++;
            }

            expect($totalBytes)->toBe(1024);
            expect($chunksPulled)->toBeGreaterThan(0);
        });

        it('pulls data line by line using readLineAsync()', function () {
            $response = await(Http::request()->stream(HttpBin::url('/stream/3')));

            $line1 = await($response->readLineAsync());
            expect($line1)->not->toBeNull();
            expect($line1)->toContain('"id"');

            $line2 = await($response->readLineAsync());
            expect($line2)->not->toBeNull();
            expect($line2)->toContain('"id"');

            $line3 = await($response->readLineAsync());
            expect($line3)->not->toBeNull();
            expect($line3)->toContain('"id"');

            $line4 = await($response->readLineAsync());
            expect($line4)->toBeNull();
        });

        it('can mix readAsync() and readAllAsync()', function () {
            $response = await(Http::request()->stream(HttpBin::url('/bytes/2048')));

            $firstChunk = await($response->readAsync(100));
            $firstChunkLength = strlen($firstChunk);
            expect($firstChunkLength)->toBeLessThanOrEqual(100);

            $rest = await($response->readAllAsync());
            
            $totalRead = $firstChunkLength + strlen($rest);
            expect($totalRead)->toBe(2048);
        });

        it('safely handles readAllAsync() on a large payload', function () {
            $response = await(Http::request()->stream(HttpBin::url('/bytes/1048576'))); // 1MB

            $data = await($response->readAllAsync());
            expect(strlen($data))->toBe(1048576);
        });

        it('returns null immediately when pulling from an empty response (204 No Content)', function () {
            $response = await(Http::request()->stream(HttpBin::url('/status/204')));

            expect($response->status())->toBe(204);
            expect(await($response->readAsync()))->toBeNull();
            expect(await($response->readAllAsync()))->toBe('');
        });

        it('returns an empty string when requesting 0 bytes via readAsync', function () {
            $response = await(Http::request()->stream(HttpBin::url('/bytes/100')));

            $chunk = await($response->readAsync(0));
            expect($chunk)->toBe('');
        });

        it('returns the full string if readLineAsync is called on data without newlines', function () {
            $response = await(Http::request()->stream(HttpBin::url('/base64/SGVsbG8gV29ybGQ=')));

            $line1 = await($response->readLineAsync());
            expect($line1)->toBe('Hello World');

            $line2 = await($response->readLineAsync());
            expect($line2)->toBeNull();
        });

        it('consistently returns null when reading after EOF is reached', function () {
            $response = await(Http::request()->stream(HttpBin::url('/bytes/10')));
            await($response->readAllAsync());

            expect(await($response->readAsync(10)))->toBeNull();
            expect(await($response->readLineAsync()))->toBeNull();
            expect(await($response->readAllAsync()))->toBe('');
        });

        it('returns only the available bytes when requesting more than the stream contains', function () {
            $response = await(Http::request()->stream(HttpBin::url('/bytes/50')));

            $chunk = await($response->readAsync(1048576));

            expect(strlen($chunk))->toBeLessThanOrEqual(50);
            
            $rest = await($response->readAllAsync());
            expect(strlen($chunk) + strlen($rest))->toBe(50);
        });

    });
});