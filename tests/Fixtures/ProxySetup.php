<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Hibla\HttpClient\HttpClient;

use function Rcalicdan\ConfigLoader\env;

final class ProxySetup
{
    public static function httpHost(): string
    {
        return (string) env('HTTP_PROXY_HOST', '127.0.0.1');
    }

    public static function httpPort(): int
    {
        return (int) env('HTTP_PROXY_PORT', 8888, convertNumeric: true);
    }

    public static function socks5Host(): string
    {
        return (string) env('SOCKS5_PROXY_HOST', '127.0.0.1');
    }

    public static function socks5Port(): int
    {
        return (int) env('SOCKS5_PROXY_PORT', 1080, convertNumeric: true);
    }

    public static function socks5User(): ?string
    {
        $v = env('SOCKS5_USER');

        return ($v !== null && $v !== '') ? (string) $v : null;
    }

    public static function socks5Pass(): ?string
    {
        $v = env('SOCKS5_PASS');

        return ($v !== null && $v !== '') ? (string) $v : null;
    }

    public static function socks4Host(): string
    {
        return (string) env('SOCKS4_PROXY_HOST', '127.0.0.1');
    }

    public static function socks4Port(): int
    {
        return (int) env('SOCKS4_PROXY_PORT', 1081, convertNumeric: true);
    }

    public static function skipIfUnreachable(string $host, int $port): void
    {
        set_error_handler(static fn () => true);
        $sock = fsockopen($host, $port, $errno, $errstr, 1);
        restore_error_handler();

        if ($sock === false) {
            test()->markTestSkipped("Proxy unreachable at {$host}:{$port} — run: composer proxy:up");
        }

        fclose($sock);
    }

    public static function client(int $timeout = 15): HttpClient
    {
        return (new HttpClient())->timeout($timeout);
    }

    public static function readPrivate(object $object, string $property): mixed
    {
        $ref = new \ReflectionProperty($object, $property);

        return $ref->getValue($object);
    }
}
