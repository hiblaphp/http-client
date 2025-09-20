<?php

namespace Hibla\Http\Interfaces;

use Hibla\Promise\Interfaces\PromiseInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Fluent HTTP client builder interface.
 * 
 * Provides a rich, chainable interface for constructing and configuring
 * HTTP requests with various options like headers, timeouts, authentication,
 * caching, retries, and more.
 */
interface HttpClientBuilderInterface extends RequestInterface
{
    public function contentType(string $type): self;
    public function accept(string $type): self;
    public function withToken(string $token): self;
    public function withUserAgent(string $userAgent): self;
    public function withBasicAuth(string $username, string $password): self;
    public function body(string $content): self;
    public function withJson(array $data): self;
    public function withForm(array $data): self;
    public function withMultipart(array $data): self;
    public function timeout(int $seconds): self;
    public function connectTimeout(int $seconds): self;
    public function redirects(bool $follow = true, int $max = 5): self;
    public function verifySSL(bool $verify = true): self;
    public function httpVersion(string $version): self;
    public function http2(): self;
    public function http3(): self;
    public function get(string $url, array $query = []): PromiseInterface;
    public function post(string $url, array $data = []): PromiseInterface;
    public function put(string $url, array $data = []): PromiseInterface;
    public function delete(string $url): PromiseInterface;
    public function send(string $method, string $url): PromiseInterface;
}