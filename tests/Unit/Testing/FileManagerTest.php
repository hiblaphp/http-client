<?php

use Hibla\HttpClient\Testing\Utilities\FileManager;

describe('FileManager', function () {
    test('can get temp path without filename', function () {
        $path = FileManager::getTempPath();
        
        expect($path)->toBeString()
            ->and(str_contains($path, sys_get_temp_dir()))->toBeTrue()
            ->and(str_contains($path, 'http_test_'))->toBeTrue();
    });

    test('can get temp path with specific filename', function () {
        $path = FileManager::getTempPath('custom_file.txt');
        
        expect($path)->toBeString()
            ->and(str_contains($path, 'custom_file.txt'))->toBeTrue();
    });

    test('can create temporary directory', function () {
        $fileManager = createFileManager();
        
        $dir = $fileManager->createTempDirectory('test_dir_');
        
        expect($dir)->toBeString()
            ->and(is_dir($dir))->toBeTrue()
            ->and(str_contains($dir, 'test_dir_'))->toBeTrue();
            
        $fileManager->cleanup();
    });

    test('throws exception when directory cannot be created', function () {
        $fileManager = new class extends FileManager {
            public function createFailingDirectory(): string {
                $tempDir = '/invalid/path/that/does/not/exist/'.uniqid();
                if (! @mkdir($tempDir, 0755, true)) {
                    throw new Exception("Cannot create temp directory: {$tempDir}");
                }
                return $tempDir;
            }
        };
        
        expect(fn() => $fileManager->createFailingDirectory())
            ->toThrow(Exception::class, 'Cannot create temp directory');
    })->skip('Skipping due to permission issues');

    test('can create temporary file with default name', function () {
        $fileManager = createFileManager();
        
        $file = $fileManager->createTempFile();
        
        expect($file)->toBeString()
            ->and(file_exists($file))->toBeTrue()
            ->and(str_contains($file, 'http_test_'))->toBeTrue();
            
        $fileManager->cleanup();
    });

    test('can create temporary file with custom name', function () {
        $fileManager = createFileManager();
        
        $file = $fileManager->createTempFile('my_test_file.txt');
        
        expect($file)->toBeString()
            ->and(file_exists($file))->toBeTrue()
            ->and(str_contains($file, 'my_test_file.txt'))->toBeTrue();
            
        $fileManager->cleanup();
    });

    test('can create temporary file with content', function () {
        $fileManager = createFileManager();
        $content = 'This is test content';
        
        $file = $fileManager->createTempFile('test.txt', $content);
        
        expect(file_get_contents($file))->toBe($content);
        
        $fileManager->cleanup();
    });

    test('creates parent directory if needed', function () {
        $fileManager = createFileManager();
        $filename = 'subdir/nested/test_file.txt';
        
        $file = $fileManager->createTempFile($filename, 'content');
        
        expect(file_exists($file))->toBeTrue()
            ->and(is_dir(dirname($file)))->toBeTrue();
            
        $fileManager->cleanup();
    });

    test('can track file manually', function () {
        $fileManager = createFileManager();
        $tempFile = tempnam(sys_get_temp_dir(), 'manual_');
        file_put_contents($tempFile, 'test');
        
        $fileManager->trackFile($tempFile);
        
        expect(file_exists($tempFile))->toBeTrue();
        
        $fileManager->cleanup();
        
        expect(file_exists($tempFile))->toBeFalse();
    });

    test('can track directory manually', function () {
        $fileManager = createFileManager();
        $tempDir = sys_get_temp_dir().'/manual_dir_'.uniqid();
        mkdir($tempDir);
        
        $fileManager->trackDirectory($tempDir);
        
        expect(is_dir($tempDir))->toBeTrue();
        
        $fileManager->cleanup();
        
        expect(is_dir($tempDir))->toBeFalse();
    });

    test('cleanup removes created files', function () {
        $fileManager = createFileManager();
        
        $file1 = $fileManager->createTempFile('test1.txt', 'content1');
        $file2 = $fileManager->createTempFile('test2.txt', 'content2');
        
        expect(file_exists($file1))->toBeTrue()
            ->and(file_exists($file2))->toBeTrue();
        
        $fileManager->cleanup();
        
        expect(file_exists($file1))->toBeFalse()
            ->and(file_exists($file2))->toBeFalse();
    });

    test('cleanup removes created directories', function () {
        $fileManager = createFileManager();
        
        $dir1 = $fileManager->createTempDirectory('dir1_');
        $dir2 = $fileManager->createTempDirectory('dir2_');
        
        expect(is_dir($dir1))->toBeTrue()
            ->and(is_dir($dir2))->toBeTrue();
        
        $fileManager->cleanup();
        
        expect(is_dir($dir1))->toBeFalse()
            ->and(is_dir($dir2))->toBeFalse();
    });

    test('cleanup removes directory contents recursively', function () {
        $fileManager = createFileManager();
        
        $dir = $fileManager->createTempDirectory();
        $subDir = $dir.'/subdir';
        mkdir($subDir);
        file_put_contents($dir.'/file1.txt', 'test1');
        file_put_contents($subDir.'/file2.txt', 'test2');
        
        expect(file_exists($dir.'/file1.txt'))->toBeTrue()
            ->and(file_exists($subDir.'/file2.txt'))->toBeTrue();
        
        $fileManager->cleanup();
        
        expect(is_dir($dir))->toBeFalse()
            ->and(is_dir($subDir))->toBeFalse();
    });

    test('cleanup cleans up temp files matching patterns', function () {
        $fileManager = createFileManager();
        $tempFile = sys_get_temp_dir().'/http_test_'.uniqid().'.tmp';

        file_put_contents($tempFile, 'test');
        
        expect(file_exists($tempFile))->toBeTrue();
        
        $fileManager->cleanup();
        
        expect(file_exists($tempFile))->toBeFalse();
    });

    test('can set auto management', function () {
        $fileManager = createFileManager();
        
        $fileManager->setAutoManagement(false);
        
        $file = $fileManager->createTempFile('no_auto.txt', 'test');
        
        expect(file_exists($file))->toBeTrue();
        
        $fileManager->cleanup();
        
        expect(file_exists($file))->toBeTrue();
        
        unlink($file);
    });

    test('auto management can be disabled in constructor', function () {
        $manager = createFileManager(autoManage: false);
        
        $file = $manager->createTempFile('no_auto.txt', 'test');
        
        expect(file_exists($file))->toBeTrue();
        
        $manager->cleanup();
        
        expect(file_exists($file))->toBeTrue();


        unlink($file);
    });

    test('does not track file twice', function () {
        $fileManager = createFileManager();
        
        $file = $fileManager->createTempFile('test.txt', 'content');
        
        $fileManager->trackFile($file);
        $fileManager->trackFile($file);
        
        expect(file_exists($file))->toBeTrue();
        
        $fileManager->cleanup();
        
        expect(file_exists($file))->toBeFalse();
    });

    test('does not track directory twice', function () {
        $fileManager = createFileManager();
        
        $dir = $fileManager->createTempDirectory();
        
        $fileManager->trackDirectory($dir);
        $fileManager->trackDirectory($dir);
        
        expect(is_dir($dir))->toBeTrue();
        
        $fileManager->cleanup();
        
        expect(is_dir($dir))->toBeFalse();
    });

    test('handles non-existent files during cleanup gracefully', function () {
        $fileManager = createFileManager();
        
        $file = $fileManager->createTempFile('test.txt', 'content');
        
        unlink($file);
        
        expect(fn() => $fileManager->cleanup())->not->toThrow(Exception::class);
    });

    test('handles non-existent directories during cleanup gracefully', function () {
        $fileManager = createFileManager();
        
        $dir = $fileManager->createTempDirectory();
        
        rmdir($dir);
        
        expect(fn() => $fileManager->cleanup())->not->toThrow(Exception::class);
    });

    test('clears tracking arrays after cleanup', function () {
        $fileManager = createFileManager();
        
         $fileManager->createTempFile('test.txt');
        $fileManager->createTempDirectory();
        
        $fileManager->cleanup();
        
        expect(fn() => $fileManager->cleanup())->not->toThrow(Exception::class);
    });

    test('tracks created parent directories', function () {
        $fileManager = createFileManager();
        $filename = 'deep/nested/path/test.txt';
        
        $file = $fileManager->createTempFile($filename, 'content');
        $parentDir = dirname($file);
        
        expect(file_exists($file))->toBeTrue()
            ->and(is_dir($parentDir))->toBeTrue();
        
        $fileManager->cleanup();
        
        expect(is_dir($parentDir))->toBeFalse();
    });

    test('multiple file managers work independently', function () {
        $manager1 = createFileManager();
        $manager2 = createFileManager();
        
        $file1 = $manager1->createTempFile('manager1.txt', 'content1');
        $file2 = $manager2->createTempFile('manager2.txt', 'content2');
        
        expect(file_exists($file1))->toBeTrue()
            ->and(file_exists($file2))->toBeTrue();
        
        $manager1->cleanup();
        
        expect(file_exists($file1))->toBeFalse()
            ->and(file_exists($file2))->toBeTrue();
        
        $manager2->cleanup();
        
        expect(file_exists($file2))->toBeFalse();
    });

    test('cleanup respects files not tracked', function () {
        $fileManager = createFileManager();
        
        $trackedFile = $fileManager->createTempFile('tracked.txt', 'tracked');
        
        // Create file manually without tracking
        $untrackedFile = sys_get_temp_dir().'/untracked_'.uniqid().'.txt';
        file_put_contents($untrackedFile, 'untracked');
        
        expect(file_exists($trackedFile))->toBeTrue()
            ->and(file_exists($untrackedFile))->toBeTrue();
        
        $fileManager->cleanup();
        
        expect(file_exists($trackedFile))->toBeFalse()
            ->and(file_exists($untrackedFile))->toBeTrue();
        
        unlink($untrackedFile);
    });

    test('can create files with empty content', function () {
        $fileManager = createFileManager();
        
        $file = $fileManager->createTempFile('empty.txt', '');
        
        expect(file_exists($file))->toBeTrue()
            ->and(file_get_contents($file))->toBe('');
            
        $fileManager->cleanup();
    });

    test('temp path uses system temp directory', function () {
        $path = FileManager::getTempPath('test.txt');
        $systemTemp = sys_get_temp_dir();
        
        expect(str_starts_with($path, $systemTemp))->toBeTrue();
    });

    test('created files have correct permissions', function () {
        $fileManager = createFileManager();
        
        $file = $fileManager->createTempFile('permissions.txt', 'test');
        
        expect(is_readable($file))->toBeTrue()
            ->and(is_writable($file))->toBeTrue();
            
        $fileManager->cleanup();
    });

    test('created directories have correct permissions', function () {
        $fileManager = createFileManager();
        
        $dir = $fileManager->createTempDirectory();
        
        expect(is_readable($dir))->toBeTrue()
            ->and(is_writable($dir))->toBeTrue();
            
        $fileManager->cleanup();
    });

    test('handles files with special characters in name', function () {
        $fileManager = createFileManager();
        
        $file = $fileManager->createTempFile('test-file_123.txt', 'content');
        
        expect(file_exists($file))->toBeTrue()
            ->and(file_get_contents($file))->toBe('content');
            
        $fileManager->cleanup();
    });

    test('cleanup handles glob pattern failure gracefully', function () {
        $fileManager = createFileManager();
        
       $fileManager->createTempFile('test.txt', 'content');
        
        expect(fn() => $fileManager->cleanup())->not->toThrow(Exception::class);
    });

    test('directory removal handles nested empty directories', function () {
        $fileManager = createFileManager();
        
        $dir = $fileManager->createTempDirectory();
        $nested1 = $dir.'/level1';
        $nested2 = $nested1.'/level2';
        $nested3 = $nested2.'/level3';
        
        mkdir($nested1);
        mkdir($nested2);
        mkdir($nested3);
        
        expect(is_dir($nested3))->toBeTrue();
        
        $fileManager->cleanup();
        
        expect(is_dir($dir))->toBeFalse();
    });

    test('can create multiple files in same directory', function () {
        $fileManager = createFileManager();
        
        $file1 = $fileManager->createTempFile('dir/file1.txt', 'content1');
        $file2 = $fileManager->createTempFile('dir/file2.txt', 'content2');
        
        expect(file_exists($file1))->toBeTrue()
            ->and(file_exists($file2))->toBeTrue()
            ->and(dirname($file1))->toBe(dirname($file2));
            
        $fileManager->cleanup();
    });

    test('cleanup removes directories in reverse order', function () {
        $fileManager = createFileManager();
        
        $dir1 = $fileManager->createTempDirectory('outer_');
        $dir2 = $dir1.'/inner';
        mkdir($dir2);
        $fileManager->trackDirectory($dir2);
        
        file_put_contents($dir2.'/file.txt', 'test');
        
        expect(is_dir($dir2))->toBeTrue();
        
        $fileManager->cleanup();
        
        expect(is_dir($dir1))->toBeFalse()
            ->and(is_dir($dir2))->toBeFalse();
    });
});