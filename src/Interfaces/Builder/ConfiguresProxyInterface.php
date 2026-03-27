<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Builder;

use Hibla\HttpClient\ProxyConfig;

/**
 * Fluent interface for outbound proxy configuration.
 *
 * The four strategies (HTTP, SOCKS4, SOCKS5, value object) are
 * mutually exclusive — setting one replaces any previously configured
 * proxy on the instance. Use noProxy() to explicitly bypass any
 * globally configured proxy for a specific request.
 */
interface ConfiguresProxyInterface
{
    /**
     * Route this request through an HTTP or HTTPS proxy.
     *
     * @param  string       $host      Proxy hostname or IP address.
     * @param  int          $port      Proxy port.
     * @param  string|null  $username  Optional proxy username.
     * @param  string|null  $password  Optional proxy password.
     */
    public function withProxy(
        string $host,
        int $port,
        ?string $username = null,
        ?string $password = null,
    ): static;

    /**
     * Route this request through a SOCKS4 proxy.
     *
     * @param  string       $host      Proxy hostname or IP address.
     * @param  int          $port      Proxy port.
     * @param  string|null  $username  Optional proxy username.
     */
    public function withSocks4Proxy(string $host, int $port, ?string $username = null): static;

    /**
     * Route this request through a SOCKS5 proxy.
     *
     * @param  string       $host      Proxy hostname or IP address.
     * @param  int          $port      Proxy port.
     * @param  string|null  $username  Optional proxy username.
     * @param  string|null  $password  Optional proxy password.
     */
    public function withSocks5Proxy(
        string $host,
        int $port,
        ?string $username = null,
        ?string $password = null,
    ): static;

    /**
     * Configure proxy from a pre-built ProxyConfig value object.
     *
     * Useful when proxy settings are constructed dynamically or
     * shared across multiple requests.
     */
    public function proxyWith(ProxyConfig $config): static;

    /**
     * Disable proxy routing for this request.
     *
     * Explicitly bypasses any globally configured proxy, ensuring
     * this request connects directly regardless of environment settings.
     */
    public function noProxy(): static;
}