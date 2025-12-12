<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

final readonly class ProxyConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public ?string $username = null,
        public ?string $password = null,
        public string $type = 'http', // 'http', 'socks4', 'socks5'
        public ?int $tunnelPort = null
    ) {
    }

    public static function http(string $host, int $port, ?string $username = null, ?string $password = null): self
    {
        return new self($host, $port, $username, $password, 'http');
    }

    public static function socks4(string $host, int $port, ?string $username = null): self
    {
        return new self($host, $port, $username, null, 'socks4');
    }

    public static function socks5(string $host, int $port, ?string $username = null, ?string $password = null): self
    {
        return new self($host, $port, $username, $password, 'socks5');
    }

    public function getProxyUrl(): string
    {
        $auth = '';
        if ($this->username !== null) {
            $auth = $this->username;
            if ($this->password !== null) {
                $auth .= ':'.$this->password;
            }
            $auth .= '@';
        }

        return "{$this->type}://{$auth}{$this->host}:{$this->port}";
    }

    public function getCurlProxyType(): int
    {
        return match ($this->type) {
            'socks4' => CURLPROXY_SOCKS4,
            'socks5' => CURLPROXY_SOCKS5,
            default => CURLPROXY_HTTP,
        };
    }
}
