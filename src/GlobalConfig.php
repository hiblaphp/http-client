<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

final class GlobalConfig
{
    private static string $userAgent = 'Hibla-HTTP-Client/1.0';
    private static ?string $cachePath = null;

    public static function setUserAgent(string $userAgent): void
    {
        self::$userAgent = $userAgent;
    }

    public static function getUserAgent(): string
    {
        return self::$userAgent;
    }

    public static function setCachePath(string $path): void
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        self::$cachePath = rtrim($normalized, DIRECTORY_SEPARATOR);
    }

    public static function getCachePath(): string
    {
        if (self::$cachePath === null) {
            return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hibla-http-cache';
        }

        return self::$cachePath;
    }

    public static function reset(): void
    {
        self::$userAgent = 'Hibla-HTTP-Client/1.0';
        self::$cachePath = null;
    }
}
