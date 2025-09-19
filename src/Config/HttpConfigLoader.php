<?php

namespace Hibla\Http\Config;

/**
 * A dedicated, standalone, and singleton configuration loader for the HTTP client.
 *
 * It independently finds the project root by searching for the 'vendor'
 * directory and loads configuration files from the 'config/http/' directory.
 * This ensures the HTTP client's configuration is completely decoupled.
 */
final class HttpConfigLoader
{
    private static ?self $instance = null;
    private ?string $rootPath = null;
    private array $config = [];

    /**
     * The constructor is private to enforce the singleton pattern.
     */
    private function __construct()
    {
        $this->rootPath = $this->findProjectRoot();

        if ($this->rootPath !== null) {
            $this->loadConfigFiles();
        }
    }

    /**
     * Gets the singleton instance of the loader.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    /**
     * Resets the singleton instance, primarily for testing.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Retrieves a configuration array by its key (the filename without .php).
     * e.g., get('client') loads and returns config/http/client.php
     *
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Returns the discovered project root path.
     *
     * @return string|null The absolute path to the project root, or null if not found.
     */
    public function getRootPath(): ?string
    {
        return $this->rootPath;
    }

    /**
     * Searches upwards from the current directory to find the project root.
     * The root is identified by the presence of a `vendor` directory.
     * This logic is self-contained and does not rely on any other loaders.
     */
    private function findProjectRoot(): ?string
    {
        $dir = __DIR__;
        // Search up to 10 levels, which is more than sufficient for any project structure.
        for ($i = 0; $i < 10; $i++) {
            if (is_dir($dir . '/vendor')) {
                return $dir;
            }

            $parentDir = dirname($dir);
            // If dirname() returns the same directory, we've reached the top.
            if ($parentDir === $dir) {
                return null;
            }
            $dir = $parentDir;
        }

        return null;
    }

    /**
     * Loads all .php files from the project root's /config/http directory.
     */
    private function loadConfigFiles(): void
    {
        if ($this->rootPath === null) {
            return;
        }

        // This path is specific to the HTTP client's configuration.
        $configDir = $this->rootPath . '/config/http';

        if (is_dir($configDir)) {
            $files = glob($configDir . '/*.php');
            if ($files !== false) {
                foreach ($files as $file) {
                    $key = basename($file, '.php');
                    $this->config[$key] = require $file;
                }
            }
        }
    }
}