<?php

namespace Hibla\HttpClient;

class GlobalConfig
{
    private static string $userAgent = 'Hibla-HTTP-Client/1.0';
    private static ?string $cachePath = null;

    /**
     * Set the global User-Agent for all requests.
     */
    public static function setUserAgent(string $userAgent): void
    {
        self::$userAgent = $userAgent;
    }

    /**
     * Get the global User-Agent.
     */
    public static function getUserAgent(): string
    {
        return self::$userAgent;
    }

    /**
     * Set the global cache directory path for file-based caching.
     */
    public static function setCachePath(string $path): void
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        self::$cachePath = rtrim($normalized, DIRECTORY_SEPARATOR);
    }

    /**
     * Get the global cache path.
     * Defaults to the system temp directory if not set.
     */
    public static function getCachePath(): string
    {
        if (self::$cachePath === null) {
            return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hibla-http-cache';
        }

        return self::$cachePath;
    }

    /**
     * Reset configuration to defaults (useful for testing).
     */
    public static function reset(): void
    {
        self::$userAgent = 'Hibla-HTTP-Client/1.0';
        self::$cachePath = null;
    }
}
