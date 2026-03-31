<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

use Hibla\HttpClient\Interfaces\Cookie\CookieJarInterface;
use Hibla\HttpClient\Interfaces\HttpClientInterface;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\HttpClient\Interfaces\StreamingResponseInterface;
use Hibla\HttpClient\Interfaces\SSEResponseInterface;

/**
 * Translates a flat fetch-style options array into fluent HttpClient calls.
 *
 * This class is intentionally stateless — every public entry point receives
 * a fresh HttpClient instance and an options array, maps them to the fluent
 * builder API, then delegates to the appropriate terminal method.
 *
 * It owns zero execution logic. All actual I/O, retry, and interceptor
 * behaviour lives in HttpClient and its handlers.
 *
 * Supported options:
 *   method            string
 *   headers           array<string, string|string[]>
 *   json              array
 *   form              array
 *   body              string
 *   auth              array{bearer?:string, basic?:array{username,password}, digest?:array{username,password}}
 *   timeout           int
 *   connect_timeout   int
 *   follow_redirects  bool
 *   max_redirects     int
 *   verify_ssl        bool
 *   user_agent        string
 *   http_version      string   (also: protocol)
 *   retry             bool | array | RetryConfig
 *   proxy             string | array | ProxyConfig
 *   cookies           array<string, string>
 *   cookie_jar        CookieJarInterface
 *   stream            bool
 *   on_chunk/onChunk  callable
 *   download          string   (also: save_to)
 *   sse               bool
 *   on_event/onEvent  callable
 *   on_error/onError  callable
 *   reconnect         bool | array | SSEReconnectConfig
 *   <int>             mixed    raw cURL option key
 */
final class FetchRequest
{
    /**
     * Translate $options onto $client and dispatch to the right terminal method.
     *
     * @param  HttpClientInterface        $client   A pre-configured builder instance.
     * @param  string                     $url
     * @param  array<int|string, mixed>   $options
     * @return PromiseInterface<mixed>
     */
    public function send(
        HttpClientInterface $client,
        string $url,
        array $options = []
    ): PromiseInterface {
        $client = $this->applyOptions($client, $options);

        if (!empty($options['sse'])) {
            return $this->dispatchSSE($client, $url, $options);
        }

        if (!empty($options['stream'])) {
            return $this->dispatchStream($client, $url, $options);
        }

        if (isset($options['download']) || isset($options['save_to'])) {
            return $this->dispatchDownload($client, $url, $options);
        }

        return $client->send(strtoupper($options['method'] ?? 'GET'), $url);
    }

    /**
     * Map all recognised string-keyed options onto the fluent builder.
     * Integer keys are forwarded as raw cURL options.
     *
     * @param  HttpClientInterface       $client
     * @param  array<int|string, mixed>  $options
     * @return HttpClientInterface
     */
    private function applyOptions(
        HttpClientInterface $client,
        array $options
    ): HttpClientInterface {
        $client = $this->applyHeaders($client, $options);
        $client = $this->applyBody($client, $options);
        $client = $this->applyAuth($client, $options);
        $client = $this->applyTransport($client, $options);
        $client = $this->applyRetry($client, $options);
        $client = $this->applyProxy($client, $options);
        $client = $this->applyCookies($client, $options);
        $client = $this->applyRawCurlOptions($client, $options);

        return $client;
    }

    private function applyHeaders(
        HttpClientInterface $client,
        array $options
    ): HttpClientInterface {
        if (isset($options['headers']) && \is_array($options['headers'])) {
            /** @var array<string, string|string[]> $headers */
            $headers = $options['headers'];
            $client  = $client->withHeaders($headers);
        }

        return $client;
    }

    private function applyBody(
        HttpClientInterface $client,
        array $options
    ): HttpClientInterface {
        if (isset($options['json']) && \is_array($options['json'])) {
            return $client->withJson($options['json']);
        }

        if (isset($options['form']) && \is_array($options['form'])) {
            return $client->withForm($options['form']);
        }

        if (isset($options['body']) && \is_string($options['body'])) {
            return $client->body($options['body']);
        }

        return $client;
    }

    /**
     * @param  array<string, mixed>  $auth
     */
    private function applyAuth(
        HttpClientInterface $client,
        array $options
    ): HttpClientInterface {
        if (!isset($options['auth']) || !\is_array($options['auth'])) {
            return $client;
        }

        $auth = $options['auth'];

        if (isset($auth['bearer']) && \is_string($auth['bearer'])) {
            return $client->withToken($auth['bearer']);
        }

        if (isset($auth['basic']) && \is_array($auth['basic'])) {
            return $client->withBasicAuth(
                \is_string($auth['basic']['username'] ?? null) ? $auth['basic']['username'] : '',
                \is_string($auth['basic']['password'] ?? null) ? $auth['basic']['password'] : '',
            );
        }

        if (isset($auth['digest']) && \is_array($auth['digest'])) {
            return $client->withDigestAuth(
                \is_string($auth['digest']['username'] ?? null) ? $auth['digest']['username'] : '',
                \is_string($auth['digest']['password'] ?? null) ? $auth['digest']['password'] : '',
            );
        }

        return $client;
    }

    private function applyTransport(
        HttpClientInterface $client,
        array $options
    ): HttpClientInterface {
        if (isset($options['timeout']) && \is_numeric($options['timeout'])) {
            $client = $client->timeout((int) $options['timeout']);
        }

        if (isset($options['connect_timeout']) && \is_numeric($options['connect_timeout'])) {
            $client = $client->connectTimeout((int) $options['connect_timeout']);
        }

        if (isset($options['follow_redirects'])) {
            $max    = isset($options['max_redirects']) && \is_numeric($options['max_redirects'])
                        ? (int) $options['max_redirects']
                        : 5;
            $client = $client->redirects((bool) $options['follow_redirects'], $max);
        }

        if (isset($options['verify_ssl'])) {
            $client = $client->verifySSL((bool) $options['verify_ssl']);
        }

        if (isset($options['user_agent']) && \is_string($options['user_agent'])) {
            $client = $client->withUserAgent($options['user_agent']);
        }

        $version = $options['http_version'] ?? $options['protocol'] ?? null;
        if (\is_string($version)) {
            $client = $client->httpVersion($version);
        }

        return $client;
    }

    private function applyRetry(
        HttpClientInterface $client,
        array $options
    ): HttpClientInterface {
        if (!isset($options['retry'])) {
            return $client;
        }

        $retry = $options['retry'];

        if ($retry instanceof RetryConfig) {
            return $client->retryWith($retry);
        }

        if ($retry === true) {
            return $client->retry();
        }

        if (\is_array($retry)) {
            return $client->retry(
                maxRetries:        isset($retry['max_retries'])        && \is_numeric($retry['max_retries'])        ? (int)   $retry['max_retries']        : 3,
                baseDelay:         isset($retry['base_delay'])         && \is_numeric($retry['base_delay'])         ? (float) $retry['base_delay']         : 1.0,
                backoffMultiplier: isset($retry['backoff_multiplier']) && \is_numeric($retry['backoff_multiplier']) ? (float) $retry['backoff_multiplier'] : 2.0,
            );
        }

        return $client;
    }

    private function applyProxy(
        HttpClientInterface $client,
        array $options
    ): HttpClientInterface {
        if (!isset($options['proxy'])) {
            return $client;
        }

        $proxy = $options['proxy'];

        if ($proxy instanceof ProxyConfig) {
            return $client->proxyWith($proxy);
        }

        if (\is_string($proxy)) {
            $config = $this->parseProxyUrl($proxy);
            return $config !== null ? $client->proxyWith($config) : $client;
        }

        if (\is_array($proxy)) {
            $config = $this->parseProxyArray($proxy);
            return $config !== null ? $client->proxyWith($config) : $client;
        }

        return $client;
    }

    private function applyCookies(
        HttpClientInterface $client,
        array $options
    ): HttpClientInterface {
        if (isset($options['cookies']) && \is_array($options['cookies'])) {
            /** @var array<string, string> $cookies */
            $cookies = $options['cookies'];
            $client  = $client->withCookies($cookies);
        }

        if (
            isset($options['cookie_jar']) &&
            $options['cookie_jar'] instanceof CookieJarInterface
        ) {
            $client = $client->useCookieJar($options['cookie_jar']);
        }

        return $client;
    }

    private function applyRawCurlOptions(
        HttpClientInterface $client,
        array $options
    ): HttpClientInterface {
        foreach ($options as $key => $value) {
            if (\is_int($key)) {
                $client = $client->withCurlOption($key, $value);
            }
        }

        return $client;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Terminal dispatch
    // ─────────────────────────────────────────────────────────────────────

    /** @return PromiseInterface<SSEResponseInterface> */
    private function dispatchSSE(
        HttpClientInterface $client,
        string $url,
        array $options
    ): PromiseInterface {
        $builder = $client->sse($url);

        $onEvent = $this->pickCallable($options, 'on_event', 'onEvent');
        $onError = $this->pickCallable($options, 'on_error', 'onError');

        if ($onEvent !== null) {
            $builder = $builder->onEvent($onEvent);
        }
        if ($onError !== null) {
            $builder = $builder->onError($onError);
        }

        $reconnect = $options['reconnect'] ?? null;
        if ($reconnect !== null) {
            $builder = $builder->reconnect($this->parseReconnectConfig($reconnect));
        }

        return $builder->connect();
    }

    /** @return PromiseInterface<StreamingResponseInterface> */
    private function dispatchStream(
        HttpClientInterface $client,
        string $url,
        array $options
    ): PromiseInterface {
        $onChunk = $this->pickCallable($options, 'on_chunk', 'onChunk');
        $method  = strtoupper($options['method'] ?? 'GET');

        return $method === 'POST'
            ? $client->streamPost($url, null, $onChunk)
            : $client->stream($url, $onChunk);
    }

    /**
     * @return PromiseInterface<array{file:string,status:int,headers:array<mixed>,protocol_version:string|null,size:int|false}>
     */
    private function dispatchDownload(
        HttpClientInterface $client,
        string $url,
        array $options
    ): PromiseInterface {
        $dest = $options['download'] ?? $options['save_to'] ?? null;

        if (!\is_string($dest)) {
            throw new \InvalidArgumentException(
                'fetch() download destination must be a string path.'
            );
        }

        return $client->download($url, $dest);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Parsing helpers
    // ─────────────────────────────────────────────────────────────────────

    private function parseProxyUrl(string $url): ?ProxyConfig
    {
        $parsed = parse_url($url);

        if (!\is_array($parsed) || !isset($parsed['host']) || !\is_string($parsed['host'])) {
            return null;
        }

        return new ProxyConfig(
            host:     $parsed['host'],
            port:     isset($parsed['port'])   && \is_int($parsed['port'])      ? $parsed['port']     : 8080,
            username: isset($parsed['user'])   && \is_string($parsed['user'])   ? $parsed['user']     : null,
            password: isset($parsed['pass'])   && \is_string($parsed['pass'])   ? $parsed['pass']     : null,
            type:     isset($parsed['scheme']) && \is_string($parsed['scheme']) ? $parsed['scheme']   : 'http',
        );
    }

    private function parseProxyArray(array $proxy): ?ProxyConfig
    {
        $host = $proxy['host'] ?? $proxy['server'] ?? '';
        $port = $proxy['port'] ?? 8080;

        if (!\is_string($host) || $host === '' || !\is_numeric($port)) {
            return null;
        }

        return new ProxyConfig(
            host:     $host,
            port:     (int) $port,
            username: isset($proxy['username']) && \is_string($proxy['username']) ? $proxy['username'] : null,
            password: isset($proxy['password']) && \is_string($proxy['password']) ? $proxy['password'] : null,
            type:     isset($proxy['type'])     && \is_string($proxy['type'])     ? $proxy['type']     : 'http',
        );
    }

    private function parseReconnectConfig(mixed $reconnect): SSEReconnectConfig
    {
        if ($reconnect instanceof SSEReconnectConfig) {
            return $reconnect;
        }

        if ($reconnect === true) {
            return new SSEReconnectConfig();
        }

        if (\is_array($reconnect)) {
            return new SSEReconnectConfig(
                enabled:           isset($reconnect['enabled'])            ? (bool)   $reconnect['enabled']                                                        : true,
                maxAttempts:       isset($reconnect['max_attempts'])       && \is_numeric($reconnect['max_attempts'])       ? (int)   $reconnect['max_attempts']       : 10,
                initialDelay:      isset($reconnect['initial_delay'])      && \is_numeric($reconnect['initial_delay'])      ? (float) $reconnect['initial_delay']      : 1.0,
                maxDelay:          isset($reconnect['max_delay'])          && \is_numeric($reconnect['max_delay'])          ? (float) $reconnect['max_delay']          : 30.0,
                backoffMultiplier: isset($reconnect['backoff_multiplier']) && \is_numeric($reconnect['backoff_multiplier']) ? (float) $reconnect['backoff_multiplier'] : 2.0,
                jitter:            isset($reconnect['jitter'])             ? (bool)   $reconnect['jitter']                                                         : true,
                onReconnect:       isset($reconnect['on_reconnect'])       && \is_callable($reconnect['on_reconnect'])       ? $reconnect['on_reconnect']               : null,
                shouldReconnect:   isset($reconnect['should_reconnect'])   && \is_callable($reconnect['should_reconnect'])   ? $reconnect['should_reconnect']           : null,
            );
        }

        return new SSEReconnectConfig();
    }

    /**
     * Return the first callable found at any of the given option keys.
     *
     * @param  array<int|string, mixed>  $options
     */
    private function pickCallable(array $options, string ...$keys): ?callable
    {
        foreach ($keys as $key) {
            if (isset($options[$key]) && \is_callable($options[$key])) {
                return $options[$key];
            }
        }

        return null;
    }
}