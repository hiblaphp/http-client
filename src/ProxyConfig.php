<?php

namespace Hibla\HttpClient;

class ProxyConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        public readonly string $type = 'http', // 'http', 'socks4', 'socks5'
        public readonly ?int $tunnelPort = null
    ) {
    }

    /**
     * Create HTTP proxy configuration
     */
    public static function http(string $host, int $port, ?string $username = null, ?string $password = null): self
    {
        return new self($host, $port, $username, $password, 'http');
    }

    /**
     * Create SOCKS4 proxy configuration
     */
    public static function socks4(string $host, int $port, ?string $username = null): self
    {
        return new self($host, $port, $username, null, 'socks4');
    }

    /**
     * Create SOCKS5 proxy configuration
     */
    public static function socks5(string $host, int $port, ?string $username = null, ?string $password = null): self
    {
        return new self($host, $port, $username, $password, 'socks5');
    }

    /**
     * Get the proxy URL string
     */
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

    /**
     * Get cURL proxy type constant
     */
    public function getCurlProxyType(): int
    {
        return match ($this->type) {
            'socks4' => CURLPROXY_SOCKS4,
            'socks5' => CURLPROXY_SOCKS5,
            default => CURLPROXY_HTTP,
        };
    }
}
