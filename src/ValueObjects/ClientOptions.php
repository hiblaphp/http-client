<?php

declare(strict_types=1);

namespace Hibla\HttpClient\ValueObjects;

use Hibla\HttpClient\Interfaces\Cookie\CookieJarInterface;
use Psr\Http\Message\StreamInterface;

final readonly class ClientOptions
{
    /**
     * @param array<string, array<string>> $headers
     * @param array{0: string, 1: string, 2: string}|null $auth
     * @param array<int|string, mixed> $additionalOptions
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public StreamInterface $body,
        public int $timeout,
        public int $connectTimeout,
        public bool $followRedirects,
        public int $maxRedirects,
        public bool $verifySSL,
        public ?string $userAgent,
        public string $protocol = '2.0',
        public ?CookieJarInterface $cookieJar = null,
        public ?ProxyConfig $proxyConfig = null,
        public ?array $auth = null,
        public array $additionalOptions = [],
        public ?RetryConfig $retryConfig = null
    ) {
    }
}
