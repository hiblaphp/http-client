<?php

namespace Hibla\HttpClient\Testing\Utilities;

use Exception;

/**
 * Manages temporary files and directories for testing.
 */
class FileManager
{
    /**
     * List of created files to clean up.
     *
     * @var array<string>
     */
    private array $createdFiles = [];

    /**
     * List of created directories to clean up.
     *
     * @var array<string>
     */
    private array $createdDirectories = [];

    /**
     * Whether to automatically manage cleanup.
     */
    private bool $autoManage;

    /**
     * Creates a new file manager.
     *
     * @param bool $autoManage Whether to automatically track files for cleanup
     */
    public function __construct(bool $autoManage = true)
    {
        $this->autoManage = $autoManage;
    }

    /**
     * Sets whether automatic management is enabled.
     *
     * @param bool $enabled True to enable auto-management
     */
    public function setAutoManagement(bool $enabled): void
    {
        $this->autoManage = $enabled;
    }

    /**
     * Gets a path in the system temp directory.
     *
     * @param string|null $filename Optional filename
     * @return string Full path
     */
    public static function getTempPath(?string $filename = null): string
    {
        $tempDir = sys_get_temp_dir();
        if ($filename === null) {
            $filename = 'http_test_'.uniqid().'.tmp';
        }

        return $tempDir.DIRECTORY_SEPARATOR.$filename;
    }

    /**
     * Creates a temporary directory.
     *
     * @param string $prefix Directory name prefix
     * @return string Path to created directory
     * @throws Exception If directory cannot be created
     */
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

    /**
     * Creates a temporary file with optional content.
     *
     * @param string|null $filename Optional filename
     * @param string $content File content
     * @return string Path to created file
     * @throws Exception If file cannot be created
     */
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

    /**
     * Cleans up all managed files and directories.
     */
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
            if ($files === false) {
                continue;
            }

            foreach ($files as $file) {
                if (is_file($file) && ! in_array($file, $this->createdFiles, true)) {
                    unlink($file);
                }
            }
        }

        $this->createdFiles = [];
        $this->createdDirectories = [];
    }

    /**
     * Tracks a file for cleanup.
     *
     * @param string $filePath Path to file
     */
    public function trackFile(string $filePath): void
    {
        if ($this->autoManage && ! in_array($filePath, $this->createdFiles, true)) {
            $this->createdFiles[] = $filePath;
        }
    }

    /**
     * Tracks a directory for cleanup.
     *
     * @param string $dirPath Path to directory
     */
    public function trackDirectory(string $dirPath): void
    {
        if ($this->autoManage && ! in_array($dirPath, $this->createdDirectories, true)) {
            $this->createdDirectories[] = $dirPath;
        }
    }

    /**
     * Recursively removes directory contents.
     *
     * @param string $dir Directory path
     */
    private function removeDirectoryContents(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $scanResult = scandir($dir);
        if ($scanResult === false) {
            return;
        }

        $files = array_diff($scanResult, ['.', '..']);
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
