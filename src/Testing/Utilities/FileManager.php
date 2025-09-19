<?php

namespace Hibla\Http\Testing\Utilities;

use Exception;

class FileManager
{
    /** @var array<string> */
    private array $createdFiles = [];

    /** @var array<string> */
    private array $createdDirectories = [];

    private bool $autoManage;

    public function __construct(bool $autoManage = true)
    {
        $this->autoManage = $autoManage;
    }

    public function setAutoManagement(bool $enabled): void
    {
        $this->autoManage = $enabled;
    }

    public static function getTempPath(?string $filename = null): string
    {
        $tempDir = sys_get_temp_dir();
        if ($filename === null) {
            $filename = 'http_test_'.uniqid().'.tmp';
        }

        return $tempDir.DIRECTORY_SEPARATOR.$filename;
    }

    public function createTempDirectory(string $prefix = 'http_test_'): string
    {
        $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.uniqid();
        if (! mkdir($tempDir, 0755, true)) {
            throw new Exception("Cannot create temp directory: {$tempDir}");
        }

        if ($this->autoManage) {
            $this->createdDirectories[] = $tempDir;
        }

        return $tempDir;
    }

    public function createTempFile(?string $filename = null, string $content = ''): string
    {
        if ($filename === null) {
            $filename = 'http_test_'.uniqid().'.tmp';
        }

        $filePath = self::getTempPath($filename);
        $directory = dirname($filePath);

        if (! is_dir($directory)) {
            if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new Exception("Cannot create directory: {$directory}");
            }
            if ($this->autoManage) {
                $this->createdDirectories[] = $directory;
            }
        }

        if (file_put_contents($filePath, $content) === false) {
            throw new Exception("Cannot create temp file: {$filePath}");
        }

        if ($this->autoManage) {
            $this->createdFiles[] = $filePath;
        }

        return $filePath;
    }

    public function cleanup(): void
    {
        foreach ($this->createdFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        foreach (array_reverse($this->createdDirectories) as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectoryContents($dir);
                rmdir($dir);
            }
        }

        $tempDir = sys_get_temp_dir();
        $patterns = [
            $tempDir.DIRECTORY_SEPARATOR.'download_*.tmp',
            $tempDir.DIRECTORY_SEPARATOR.'http_test_*',
            $tempDir.DIRECTORY_SEPARATOR.'*test_*.tmp',
        ];

        foreach ($patterns as $pattern) {
            $files = glob($pattern);
            foreach ($files as $file) {
                if (is_file($file) && ! in_array($file, $this->createdFiles)) {
                    unlink($file);
                }
            }
        }

        $this->createdFiles = [];
        $this->createdDirectories = [];
    }

    public function trackFile(string $filePath): void
    {
        if ($this->autoManage && ! in_array($filePath, $this->createdFiles)) {
            $this->createdFiles[] = $filePath;
        }
    }

    public function trackDirectory(string $dirPath): void
    {
        if ($this->autoManage && ! in_array($dirPath, $this->createdDirectories)) {
            $this->createdDirectories[] = $dirPath;
        }
    }

    private function removeDirectoryContents(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.DIRECTORY_SEPARATOR.$file;
            if (is_dir($path)) {
                $this->removeDirectoryContents($path);
                rmdir($path);
            } else {
                unlink($path);
            }
        }
    }
}
