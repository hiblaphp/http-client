<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use function Rcalicdan\ConfigLoader\env;

final class HttpBin
{
    public static function host(): string
    {
        return (string) env('HTTPBIN_HOST', '127.0.0.1');
    }

    public static function port(): int
    {
        return (int) env('HTTPBIN_PORT', 8080, convertNumeric: true);
    }

    public static function baseUrl(): string
    {
        return 'http://' . self::host() . ':' . self::port();
    }

    public static function url(string $path): string
    {
        return self::baseUrl() . '/' . ltrim($path, '/');
    }

    public static function internalHost(): string
    {
        return (string) env('HTTPBIN_INTERNAL_HOST', 'hibla_httpbin');
    }

    public static function internalPort(): int
    {
        return (int) env('HTTPBIN_INTERNAL_PORT', 8080, convertNumeric: true);
    }

    public static function internalBaseUrl(): string
    {
        return 'http://' . self::internalHost() . ':' . self::internalPort();
    }

    public static function proxyUrl(string $path): string
    {
        return self::internalBaseUrl() . '/' . ltrim($path, '/');
    }

    public static function socksHost(): string
    {
        return (string) env('HTTPBIN_SOCKS_HOST', 'host.docker.internal');
    }

    public static function socksPort(): int
    {
        return (int) env('HTTPBIN_SOCKS_PORT', 8080, convertNumeric: true);
    }

    public static function socksBaseUrl(): string
    {
        return 'http://' . self::socksHost() . ':' . self::socksPort();
    }

    public static function socksProxyUrl(string $path): string
    {
        return self::socksBaseUrl() . '/' . ltrim($path, '/');
    }

    public static function skipIfUnreachable(): void
    {
        set_error_handler(static fn () => true); // block PHPUnit's error handler
        $sock = fsockopen(self::host(), self::port(), $errno, $errstr, 1);
        restore_error_handler();

        if ($sock === false) {
            test()->markTestSkipped('httpbin unreachable at ' . self::baseUrl() . ' — run: composer httpbin:up');
        }

        fclose($sock);
    }

    public static function isReachable(): bool
    {
        set_error_handler(static fn () => true); // block PHPUnit's error handler
        $sock = fsockopen(self::host(), self::port(), $errno, $errstr, 1);
        restore_error_handler();

        if ($sock === false) {
            return false;
        }

        fclose($sock);

        return true;
    }
}
