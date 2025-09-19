<?php

namespace Hibla\Http;

use Psr\Http\Message\UriInterface;

/**
 * A PSR-7 compliant implementation of a Uniform Resource Identifier (URI).
 *
 * Provides parsing and manipulation of URI components including scheme, host, port,
 * path, query, fragment, and user information with immutable operations.
 */
class Uri implements UriInterface
{
    protected string $scheme = '';
    protected string $host = '';
    protected ?int $port = null;
    protected string $path = '/';
    protected string $query = '';
    protected string $fragment = '';
    protected string $userInfo = '';

    /**
     * Initializes a new URI instance by parsing a URI string.
     *
     * @param  string  $uri  The URI to parse.
     *
     * @throws \InvalidArgumentException If the given URI cannot be parsed.
     */
    public function __construct(string $uri = '')
    {
        if ($uri === '') {
            return;
        }

        $parts = parse_url($uri);

        if ($parts === false) {
            throw new \InvalidArgumentException("Invalid URI: $uri");
        }

        $this->scheme = isset($parts['scheme']) && is_string($parts['scheme']) ? $parts['scheme'] : '';
        $this->host = isset($parts['host']) && is_string($parts['host']) ? $parts['host'] : '';
        $this->port = isset($parts['port']) && is_int($parts['port']) ? $parts['port'] : null;
        $this->path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '/';
        $this->query = isset($parts['query']) && is_string($parts['query']) ? $parts['query'] : '';
        $this->fragment = isset($parts['fragment']) && is_string($parts['fragment']) ? $parts['fragment'] : '';

        if (isset($parts['user']) && is_string($parts['user'])) {
            $password = isset($parts['pass']) && is_string($parts['pass']) ? $parts['pass'] : null;
            $this->userInfo = $parts['user'].($password !== null ? ':'.$password : '');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthority(): string
    {
        $authority = $this->getUserInfo() !== '' ? $this->getUserInfo().'@' : '';
        $authority .= $this->host;

        if ($this->port !== null && ! $this->isStandardPort($this->scheme, $this->port)) {
            $authority .= ':'.$this->port;
        }

        return $authority;
    }

    /**
     * {@inheritdoc}
     */
    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    /**
     * {@inheritdoc}
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * {@inheritdoc}
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * {@inheritdoc}
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * {@inheritdoc}
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * {@inheritdoc}
     */
    public function getFragment(): string
    {
        return $this->fragment;
    }

    /**
     * {@inheritdoc}
     */
    public function withScheme(string $scheme): UriInterface
    {
        if ($this->scheme === $scheme) {
            return $this;
        }

        $clone = clone $this;
        $clone->scheme = strtolower($scheme);

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $userInfo = $user.($password !== null ? ':'.$password : '');

        if ($this->userInfo === $userInfo) {
            return $this;
        }

        $clone = clone $this;
        $clone->userInfo = $userInfo;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withHost(string $host): UriInterface
    {
        if ($this->host === $host) {
            return $this;
        }

        $clone = clone $this;
        $clone->host = strtolower($host);

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withPort(?int $port): UriInterface
    {
        if ($this->port === $port) {
            return $this;
        }

        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new \InvalidArgumentException('Port must be between 1 and 65535 or null');
        }

        $clone = clone $this;
        $clone->port = $port;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withPath(string $path): UriInterface
    {
        if ($this->path === $path) {
            return $this;
        }

        $clone = clone $this;
        $clone->path = $path;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withQuery(string $query): UriInterface
    {
        if ($this->query === $query) {
            return $this;
        }

        $clone = clone $this;
        $clone->query = $query;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function withFragment(string $fragment): UriInterface
    {
        if ($this->fragment === $fragment) {
            return $this;
        }

        $clone = clone $this;
        $clone->fragment = $fragment;

        return $clone;
    }

    /**
     * {@inheritdoc}
     */
    public function __toString(): string
    {
        $uri = '';

        if ($this->scheme !== '') {
            $uri .= $this->scheme.'://';
        }

        $authority = $this->getAuthority();
        if ($authority !== '') {
            $uri .= $authority;
        }

        if ($this->path !== '') {
            $uri .= $this->path;
        }

        if ($this->query !== '') {
            $uri .= '?'.$this->query;
        }

        if ($this->fragment !== '') {
            $uri .= '#'.$this->fragment;
        }

        return $uri;
    }

    /**
     * Checks if the given port is a standard port for the given scheme.
     *
     * @param  string  $scheme  The URI scheme
     * @param  int  $port  The port number
     * @return bool True if it's a standard port, false otherwise
     */
    private function isStandardPort(string $scheme, int $port): bool
    {
        return match (strtolower($scheme)) {
            'http' => $port === 80,
            'https' => $port === 443,
            'ftp' => $port === 21,
            'ftps' => $port === 990,
            default => false,
        };
    }
}
