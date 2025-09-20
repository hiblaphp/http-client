<?php

namespace Hibla\Http\Interfaces;

use Hibla\Http\CacheConfig;
use Hibla\Http\ProxyConfig;
use Hibla\Http\RetryConfig;
use Hibla\Http\SSE\SSEReconnectConfig;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;

/**
 * Advanced HTTP client interface with specialized functionality.
 * 
 * Extends the basic client builder with advanced features like retries,
 * caching, streaming, downloading, proxy support, and Server-Sent Events.
 */
interface AdvancedHttpClientInterface extends HttpClientBuilderInterface
{
  public function retry(int $maxRetries = 3, float $baseDelay = 1.0, float $backoffMultiplier = 2.0): self;
  public function retryWith(RetryConfig $config): self;
  public function noRetry(): self;
  public function cache(int $ttlSeconds = 3600, bool $respectServerHeaders = true): self;
  public function cacheWith(CacheConfig $config): self;
  public function withCookie(string $name, string $value): self;
  public function withCookies(array $cookies): self;
  public function withCookieJar(): self;
  public function withFileCookieJar(string $filename, bool $includeSessionCookies = false): self;
  public function useCookieJar(CookieJarInterface $cookieJar): self;
  public function withAllCookiesSaved(string $filename): self;
  public function clearCookies(): self;
  public function getCookieJar(): ?CookieJarInterface;
  public function cookieWithAttributes(string $name, string $value, array $attributes = []): self;
  public function proxy(string $host, int $port, ?string $username = null, ?string $password = null): self;
  public function socks4Proxy(string $host, int $port, ?string $username = null): self;
  public function socks5Proxy(string $host, int $port, ?string $username = null, ?string $password = null): self;
  public function proxyWith(ProxyConfig $config): self;
  public function noProxy(): self;
  public function stream(string $url, ?callable $onChunk = null): CancellablePromiseInterface;
  public function streamPost(string $url, $body = null, ?callable $onChunk = null): CancellablePromiseInterface;
  public function download(string $url, string $destination): CancellablePromiseInterface;
  public function sseDataFormat(string $format = 'array'): self;
  public function sse(string $url, ?callable $onEvent = null, ?callable $onError = null, ?SSEReconnectConfig $reconnectConfig = null): CancellablePromiseInterface;
  public function sseMap(callable $mapper): self;
  public function sseReconnect(bool $enabled = true, int $maxAttempts = 10, float $initialDelay = 1.0, float $maxDelay = 30.0, float $backoffMultiplier = 2.0, bool $jitter = true, array $retryableErrors = [], ?callable $onReconnect = null, ?callable $shouldReconnect = null): self;
  public function sseReconnectWith(SSEReconnectConfig $config): self;
  public function noSseReconnect(): self;
  public function withFile(string $name, string $filePath, ?string $fileName = null, ?string $contentType = null): self;
  public function withFiles(array $files): self;
  public function multipartWithFiles(array $data = [], array $files = []): self;
}
