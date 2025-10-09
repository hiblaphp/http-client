<?php

use Hibla\HttpClient\Exceptions\HttpStreamException;
use Hibla\HttpClient\Stream;
use Hibla\HttpClient\UploadedFile;

$tempFile = null;
$tempFileContent = 'Test file content.';
$targetDir = null;

beforeEach(function () use (&$tempFile, $tempFileContent, &$targetDir) {
    $tempFile = tempnam(sys_get_temp_dir(), 'upl');
    file_put_contents($tempFile, $tempFileContent);

    $targetDir = sys_get_temp_dir() . '/' . uniqid('target');
    mkdir($targetDir);
});

afterEach(function () use (&$tempFile, &$targetDir) {
    if (file_exists($tempFile)) {
        unlink($tempFile);
    }
    if (is_dir($targetDir)) {
        $files = glob($targetDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($targetDir);
    }
});

describe('UploadedFile Construction and Getters', function () use (&$tempFile, $tempFileContent) {
    it('constructs from a file path', function () use (&$tempFile, $tempFileContent) {
        $file = new UploadedFile(
            $tempFile,
            strlen($tempFileContent),
            UPLOAD_ERR_OK,
            'original.txt',
            'text/plain'
        );

        expect($file->getSize())->toBe(strlen($tempFileContent));
        expect($file->getError())->toBe(UPLOAD_ERR_OK);
        expect($file->getClientFilename())->toBe('original.txt');
        expect($file->getClientMediaType())->toBe('text/plain');
        expect($file->getStream()->getContents())->toBe($tempFileContent);
    });

    it('constructs from a stream', function () use ($tempFileContent) {
        $stream = Stream::fromString($tempFileContent);
        $file = new UploadedFile($stream, $stream->getSize(), UPLOAD_ERR_OK);

        expect($file->getStream())->toBe($stream);
    });

    it('handles upload errors in the constructor', function () {
        $file = new UploadedFile(null, 0, UPLOAD_ERR_NO_FILE);

        expect($file->getError())->toBe(UPLOAD_ERR_NO_FILE);
        expect($file->getErrorMessage())->toContain('No file was uploaded');

        expect(fn () => $file->getStream())->toThrow(HttpStreamException::class);
    });
});

describe('moveTo Operation', function () use (&$tempFile, &$targetDir, $tempFileContent) {
    it('moves a file from a path to a target destination', function () use (&$tempFile, &$targetDir, $tempFileContent) {
        $file = new UploadedFile($tempFile, null, UPLOAD_ERR_OK);
        $targetPath = $targetDir . '/moved.txt';

        $file->moveTo($targetPath);

        expect(file_exists($tempFile))->toBeFalse();
        expect(file_exists($targetPath))->toBeTrue();
        expect(file_get_contents($targetPath))->toBe($tempFileContent);
    });

    it('moves a file from a stream to a target destination', function () use (&$targetDir, $tempFileContent) {
        $stream = Stream::fromString($tempFileContent);
        $file = new UploadedFile($stream);
        $targetPath = $targetDir . '/streamed.txt';

        $file->moveTo($targetPath);

        expect(file_exists($targetPath))->toBeTrue();
        expect(file_get_contents($targetPath))->toBe($tempFileContent);
    });

    it('throws an exception if trying to move a file twice', function () use (&$tempFile, &$targetDir) {
        $file = new UploadedFile($tempFile);
        $targetPath = $targetDir . '/first-move.txt';
        $file->moveTo($targetPath);

        expect(fn () => $file->moveTo($targetDir . '/second-move.txt'))
            ->toThrow(HttpStreamException::class, 'File has already been moved')
        ;
    });

    it('throws an exception if trying to get stream after move', function () use (&$tempFile, &$targetDir) {
        $file = new UploadedFile($tempFile);
        $targetPath = $targetDir . '/moved.txt';
        $file->moveTo($targetPath);

        expect(fn () => $file->getStream())
            ->toThrow(HttpStreamException::class, 'Cannot retrieve stream after it has been moved')
        ;
    });

    it('throws an exception if target directory is not writable', function () use (&$tempFile) {
        $targetDir = '/this/directory/should/not/exist';
        $targetPath = $targetDir . '/file.txt';
        $file = new UploadedFile($tempFile);

        expect(fn () => $file->moveTo($targetPath))
            ->toThrow(HttpStreamException::class, 'Target directory is not writable')
        ;
    });
});
