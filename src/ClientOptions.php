<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

use Hibla\HttpClient\Interfaces\CookieJarInterface;
use Hibla\HttpClient\ProxyConfig;
use Hibla\HttpClient\RetryConfig;
use Hibla\HttpClient\Stream;

final readonly class ClientOptions
{
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public Stream $body,
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
    ) {}
}
