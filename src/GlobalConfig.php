<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

final class GlobalConfig
{
    private static string $userAgent = 'Hibla-HTTP-Client/1.0';

    public static function setUserAgent(string $userAgent): void
    {
        self::$userAgent = $userAgent;
    }

    public static function getUserAgent(): string
    {
        return self::$userAgent;
    }

    public static function reset(): void
    {
        self::$userAgent = 'Hibla-HTTP-Client/1.0';
    }
}
