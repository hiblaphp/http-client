<?php

use Hibla\HttpClient\Http;
use Hibla\HttpClient\Testing\TestingHttpHandler;

beforeEach(function () {
    Http::startTesting();
});

afterEach(function () {
    Http::stopTesting();
});

describe('Streaming Features', function () {
    it('handles streaming with an onChunk callback', function () {
        Http::mock()
            ->url('/stream')
            ->respondWithChunks(['first chunk', ' second chunk', ' last chunk'])
            ->register()
        ;

        $receivedChunks = [];
        $onChunkCallback = function (string $chunk) use (&$receivedChunks) {
            $receivedChunks[] = $chunk;
        };

        Http::stream('/stream', $onChunkCallback)->await();

        Http::assertStreamMade('/stream');
        expect($receivedChunks)->toBe(['first chunk', ' second chunk', ' last chunk']);
    });

    it('downloads a file to the specified destination', function () {
        $mockedContent = 'This is the content of the downloaded file.';
        Http::mock()
            ->url('/download/file.txt')
            ->downloadFile($mockedContent, 'file.txt')
            ->register()
        ;

        /** @var TestingHttpHandler $handler */
        $handler = Http::getTestingHandler();
        $destination = $handler->createTempFile();

        $result = Http::download('/download/file.txt', $destination)->await();

        Http::assertDownloadMade('/download/file.txt', $destination);
        expect($result['file'])->toBe($destination);
        expect(file_get_contents($destination))->toBe($mockedContent);
    });
});
