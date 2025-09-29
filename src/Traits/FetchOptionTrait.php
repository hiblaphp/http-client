<?php

namespace Hibla\Http\Traits;

use Hibla\Http\CacheConfig;
use Hibla\Http\Interfaces\CookieJarInterface;
use Hibla\Http\ProxyConfig;
use Hibla\Http\RetryConfig;
use Symfony\Contracts\Cache\CacheInterface;

trait FetchOptionTrait
{
    /**
     * Normalizes fetch options from various formats to cURL options.
     *
     * @param  string  $url  The target URL.
     * @param  array<int|string, mixed>  $options  The options to normalize.
     * @param  bool  $ensureSSEHeaders  Whether to ensure SSE-specific headers are set.
     * @return array<int, mixed> Normalized cURL options.
     */
    public function normalizeFetchOptions(string $url, array $options, bool $ensureSSEHeaders = false): array
    {
        $cookieOptions = $this->extractCookieOptions($options);

        $cleanOptions = array_filter($options, function ($key) {
            return ! in_array($key, [
                'stream',
                'on_chunk',
                'onChunk',
                'download',
                'save_to',
                'retry',
                'cache',
                'retry_config',
                'cache_config',
                'proxy',
                'sse',
                'on_event',
                'onEvent',
                'on_error',
                'onError',
                'reconnect',
                'cookie_jar',
                'cookies',
                'cookie',
            ], true);
        }, ARRAY_FILTER_USE_KEY);

        if ($this->isCurlOptionsFormat($cleanOptions)) {
            /** @var array<int, mixed> */
            $curlOptions = array_filter($cleanOptions, fn($key) => is_int($key), ARRAY_FILTER_USE_KEY);

            $curlOptions[CURLOPT_URL] = $url;

            if (! isset($curlOptions[CURLOPT_RETURNTRANSFER])) {
                $curlOptions[CURLOPT_RETURNTRANSFER] = true;
            }
            if (! isset($curlOptions[CURLOPT_HEADER])) {
                $curlOptions[CURLOPT_HEADER] = true;
            }
            if (! isset($curlOptions[CURLOPT_NOBODY])) {
                $curlOptions[CURLOPT_NOBODY] = false;
            }

            if ($ensureSSEHeaders) {
                $this->ensureSSEHeaders($curlOptions);
            }

            return $curlOptions;
        }

        /** @var array<int, mixed> $curlOptions */
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
        ];

        if (isset($options['method']) && is_string($options['method'])) {
            $curlOptions[CURLOPT_CUSTOMREQUEST] = strtoupper($options['method']);
        }

        if (isset($options['headers']) && is_array($options['headers'])) {
            $headerStrings = [];
            foreach ($options['headers'] as $name => $value) {
                if (is_string($name) && (is_string($value) || is_scalar($value))) {
                    $headerStrings[] = "{$name}: {$value}";
                }
            }
            $curlOptions[CURLOPT_HTTPHEADER] = $headerStrings;
        }

        if (isset($options['http_version']) && is_string($options['http_version'])) {
            $curlOptions[CURLOPT_HTTP_VERSION] = match ($options['http_version']) {
                '2.0', '2' => CURL_HTTP_VERSION_2TLS,
                '3.0', '3' => defined('CURL_HTTP_VERSION_3')
                    ? CURL_HTTP_VERSION_3
                    : CURL_HTTP_VERSION_1_1,
                '1.0' => CURL_HTTP_VERSION_1_0,
                default => CURL_HTTP_VERSION_2TLS,
            };
        }

        if (isset($options['protocol']) && is_string($options['protocol'])) {
            $curlOptions[CURLOPT_HTTP_VERSION] = match ($options['protocol']) {
                '2.0', '2' => CURL_HTTP_VERSION_2TLS,
                '3.0', '3' => defined('CURL_HTTP_VERSION_3')
                    ? CURL_HTTP_VERSION_3
                    : CURL_HTTP_VERSION_1_1,
                '1.0' => CURL_HTTP_VERSION_1_0,
                default => CURL_HTTP_VERSION_2TLS,
            };
        }

        if (isset($options['body'])) {
            $curlOptions[CURLOPT_POSTFIELDS] = $options['body'];
        }

        if (isset($options['json']) && is_array($options['json'])) {
            $curlOptions[CURLOPT_POSTFIELDS] = json_encode($options['json']);
            $headers = [];
            if (isset($curlOptions[CURLOPT_HTTPHEADER]) && is_array($curlOptions[CURLOPT_HTTPHEADER])) {
                $headers = $curlOptions[CURLOPT_HTTPHEADER];
            }
            $headers[] = 'Content-Type: application/json';
            $curlOptions[CURLOPT_HTTPHEADER] = $headers;
        }

        if (isset($options['form']) && is_array($options['form'])) {
            $curlOptions[CURLOPT_POSTFIELDS] = http_build_query($options['form']);
            $headers = [];
            if (isset($curlOptions[CURLOPT_HTTPHEADER]) && is_array($curlOptions[CURLOPT_HTTPHEADER])) {
                $headers = $curlOptions[CURLOPT_HTTPHEADER];
            }
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $curlOptions[CURLOPT_HTTPHEADER] = $headers;
        }

        if (isset($options['timeout']) && is_numeric($options['timeout'])) {
            $curlOptions[CURLOPT_TIMEOUT] = (int) $options['timeout'];
        }

        if (isset($options['connect_timeout']) && is_numeric($options['connect_timeout'])) {
            $curlOptions[CURLOPT_CONNECTTIMEOUT] = (int) $options['connect_timeout'];
        }

        if (isset($options['follow_redirects'])) {
            $curlOptions[CURLOPT_FOLLOWLOCATION] = (bool) $options['follow_redirects'];
        }

        if (isset($options['max_redirects']) && is_numeric($options['max_redirects'])) {
            $curlOptions[CURLOPT_MAXREDIRS] = (int) $options['max_redirects'];
        }

        if (isset($options['verify_ssl'])) {
            $verifySSL = (bool) $options['verify_ssl'];
            $curlOptions[CURLOPT_SSL_VERIFYPEER] = $verifySSL;
            $curlOptions[CURLOPT_SSL_VERIFYHOST] = $verifySSL ? 2 : 0;
        }

        if (isset($options['user_agent']) && is_string($options['user_agent'])) {
            $curlOptions[CURLOPT_USERAGENT] = $options['user_agent'];
        }

        if (isset($options['auth']) && is_array($options['auth'])) {
            $auth = $options['auth'];

            if (isset($auth['bearer']) && is_string($auth['bearer'])) {
                $headers = [];
                if (isset($curlOptions[CURLOPT_HTTPHEADER]) && is_array($curlOptions[CURLOPT_HTTPHEADER])) {
                    $headers = $curlOptions[CURLOPT_HTTPHEADER];
                }
                $headers[] = 'Authorization: Bearer ' . $auth['bearer'];
                $curlOptions[CURLOPT_HTTPHEADER] = $headers;
            }

            if (isset($auth['basic']) && is_array($auth['basic'])) {
                $basic = $auth['basic'];
                if (
                    isset($basic['username'], $basic['password']) &&
                    is_string($basic['username']) && is_string($basic['password'])
                ) {
                    $curlOptions[CURLOPT_USERPWD] = $basic['username'] . ':' . $basic['password'];
                    $curlOptions[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
                }
            }
        }

        $this->addProxyOptionsFromArray($curlOptions, $options);

        if ($cookieOptions !== null) {
            $this->applyCookiesToCurlOptions($curlOptions, $cookieOptions);
        }

        if ($ensureSSEHeaders) {
            $this->ensureSSEHeaders($curlOptions);
        }

        return $curlOptions;
    }

    /**
     * Ensures proper headers are set for SSE connections.
     *
     * @param  array<int, mixed>  &$curlOptions  cURL options array passed by reference
     */
    protected function ensureSSEHeaders(array &$curlOptions): void
    {
        $headers = $curlOptions[CURLOPT_HTTPHEADER] ?? [];
        $hasAccept = false;
        $hasCache = false;

        foreach ($headers as $header) {
            if (stripos($header, 'Accept:') === 0) {
                $hasAccept = true;
            }
            if (stripos($header, 'Cache-Control:') === 0) {
                $hasCache = true;
            }
        }

        if (! $hasAccept) {
            $headers[] = 'Accept: text/event-stream';
        }
        if (! $hasCache) {
            $headers[] = 'Cache-Control: no-cache';
        }

        $curlOptions[CURLOPT_HTTPHEADER] = $headers;
    }

    /**
     * Extract proxy configuration from fetch options.
     *
     * @param  array<int|string, mixed>  $options
     */
    protected function extractProxyConfig(array $options): ?ProxyConfig
    {
        if (! isset($options['proxy'])) {
            return null;
        }

        $proxy = $options['proxy'];

        if ($proxy instanceof ProxyConfig) {
            return $proxy;
        }

        if (is_string($proxy)) {
            // Parse proxy URL string like "http://user:pass@host:port"
            return $this->parseProxyUrl($proxy);
        }

        if (is_array($proxy)) {
            $host = $proxy['host'] ?? $proxy['server'] ?? '';
            $port = $proxy['port'] ?? 8080;

            if (empty($host) || ! is_numeric($port)) {
                return null;
            }

            return new ProxyConfig(
                host: $host,
                port: (int) $port,
                username: $proxy['username'] ?? $proxy['user'] ?? null,
                password: $proxy['password'] ?? $proxy['pass'] ?? null,
                type: $proxy['type'] ?? 'http'
            );
        }

        return null;
    }

    /**
     * Parse a proxy URL string into a ProxyConfig object.
     */
    private function parseProxyUrl(string $proxyUrl): ?ProxyConfig
    {
        $parsed = parse_url($proxyUrl);
        if (! $parsed || ! isset($parsed['host'])) {
            return null;
        }

        return new ProxyConfig(
            host: $parsed['host'],
            port: $parsed['port'] ?? 8080,
            username: $parsed['user'] ?? null,
            password: $parsed['pass'] ?? null,
            type: $parsed['scheme'] ?? 'http'
        );
    }

    /**
     * Add proxy options to cURL options array.
     *
     * @param  array<int, mixed>  &$curlOptions
     * @param  array<int|string, mixed>  $options
     */
    private function addProxyOptionsFromArray(array &$curlOptions, array $options): void
    {
        $proxyConfig = $this->extractProxyConfig($options);

        if ($proxyConfig === null) {
            return;
        }

        $curlOptions[CURLOPT_PROXY] = $proxyConfig->host . ':' . $proxyConfig->port;
        $curlOptions[CURLOPT_PROXYTYPE] = $proxyConfig->getCurlProxyType();

        if ($proxyConfig->username !== null) {
            $proxyAuth = $proxyConfig->username;
            if ($proxyConfig->password !== null) {
                $proxyAuth .= ':' . $proxyConfig->password;
            }
            $curlOptions[CURLOPT_PROXYUSERPWD] = $proxyAuth;
        }

        // Configure tunneling based on proxy type
        if (in_array($proxyConfig->type, ['socks4', 'socks5'])) {
            $curlOptions[CURLOPT_HTTPPROXYTUNNEL] = false; // Usually not needed for SOCKS
        } else {
            $curlOptions[CURLOPT_HTTPPROXYTUNNEL] = true; // Usually needed for HTTP proxies with HTTPS
        }
    }

    private function isCurlOptionsFormat(array $options): bool
    {
        foreach (array_keys($options) as $key) {
            if (is_int($key) && $key > 0) {
                return true;
            }
        }

        return false;
    }

    private function extractCacheConfig(array $options): ?CacheConfig
    {
        if (! isset($options['cache'])) {
            return null;
        }

        $cache = $options['cache'];

        if ($cache === true) {
            return new CacheConfig;
        }

        // Handle CacheConfig object
        if ($cache instanceof CacheConfig) {
            return $cache;
        }

        // Handle array configuration
        if (is_array($cache)) {
            $cacheInstance = null;
            if (isset($cache['cache_instance']) && $cache['cache_instance'] instanceof CacheInterface) {
                $cacheInstance = $cache['cache_instance'];
            }

            $ttl = $cache['ttl'] ?? 3600;

            return new CacheConfig(
                ttlSeconds: is_numeric($ttl) ? (int) $ttl : 3600,
                respectServerHeaders: (bool) ($cache['respect_server_headers'] ?? true),
                cache: $cacheInstance
            );
        }

        // Handle integer as TTL
        if (is_int($cache)) {
            return new CacheConfig($cache);
        }

        return null;
    }

    /**
     * Extracts retry configuration from options array.
     *
     * @param  array<int|string, mixed>  $options
     */
    private function extractRetryConfig(array $options): ?RetryConfig
    {
        if (! isset($options['retry'])) {
            return null;
        }

        $retry = $options['retry'];

        if ($retry === true) {
            return new RetryConfig;
        }

        if ($retry instanceof RetryConfig) {
            return $retry;
        }

        if (is_array($retry)) {
            $retryableStatusCodes = [408, 429, 500, 502, 503, 504];
            if (isset($retry['retryable_status_codes']) && is_array($retry['retryable_status_codes'])) {
                $codes = [];
                foreach ($retry['retryable_status_codes'] as $code) {
                    if (is_numeric($code)) {
                        $codes[] = (int) $code;
                    }
                }
                $retryableStatusCodes = $codes;
            }

            $retryableExceptions = [
                'cURL error',
                'timeout',
                'connection failed',
                'Could not resolve host',
                'Resolving timed out',
                'Connection timed out',
                'SSL connection timeout',
            ];
            if (isset($retry['retryable_exceptions']) && is_array($retry['retryable_exceptions'])) {
                $exceptions = [];
                foreach ($retry['retryable_exceptions'] as $exception) {
                    if (is_scalar($exception)) {
                        $exceptions[] = (string) $exception;
                    }
                }
                $retryableExceptions = $exceptions;
            }

            $maxRetries = $retry['max_retries'] ?? 3;
            $baseDelay = $retry['base_delay'] ?? 1.0;
            $maxDelay = $retry['max_delay'] ?? 60.0;
            $backoffMultiplier = $retry['backoff_multiplier'] ?? 2.0;

            return new RetryConfig(
                maxRetries: is_numeric($maxRetries) ? (int) $maxRetries : 3,
                baseDelay: is_numeric($baseDelay) ? (float) $baseDelay : 1.0,
                maxDelay: is_numeric($maxDelay) ? (float) $maxDelay : 60.0,
                backoffMultiplier: is_numeric($backoffMultiplier) ? (float) $backoffMultiplier : 2.0,
                jitter: (bool) ($retry['jitter'] ?? true),
                retryableStatusCodes: $retryableStatusCodes,
                retryableExceptions: $retryableExceptions
            );
        }

        return null;
    }

    private function extractCookieOptions(array &$options): ?array
    {
        $cookieOptions = [];

        // Extract cookie jar if provided
        if (isset($options['cookie_jar'])) {
            $cookieOptions['jar'] = $options['cookie_jar'];
            unset($options['cookie_jar']);
        }

        // Extract individual cookies
        if (isset($options['cookies']) && is_array($options['cookies'])) {
            $cookieOptions['cookies'] = $options['cookies'];
            unset($options['cookies']);
        }

        // Extract single cookie
        if (isset($options['cookie']) && is_string($options['cookie'])) {
            $cookieOptions['cookie_header'] = $options['cookie'];
            unset($options['cookie']);
        }

        return !empty($cookieOptions) ? $cookieOptions : null;
    }

    private function applyCookiesToCurlOptions(array &$curlOptions, array $cookieOptions): void
    {
        $cookieHeaders = [];

        // Handle cookie jar
        if (isset($cookieOptions['jar']) && $cookieOptions['jar'] instanceof CookieJarInterface) {
            $jar = $cookieOptions['jar'];
            $url = $curlOptions[CURLOPT_URL] ?? '';

            if ($url) {
                $parsedUrl = parse_url($url);
                $domain = $parsedUrl['host'] ?? '';
                $path = $parsedUrl['path'] ?? '/';
                $isSecure = ($parsedUrl['scheme'] ?? 'http') === 'https';

                $cookieHeader = $jar->getCookieHeader($domain, $path, $isSecure);
                if ($cookieHeader) {
                    $cookieHeaders[] = $cookieHeader;
                }
            }

            // Store jar reference for response handling
            $curlOptions['_cookie_jar'] = $jar;
        }

        // Handle individual cookies array
        if (isset($cookieOptions['cookies']) && is_array($cookieOptions['cookies'])) {
            $cookies = [];
            foreach ($cookieOptions['cookies'] as $name => $value) {
                if (is_string($name) && is_scalar($value)) {
                    $cookies[] = $name . '=' . urlencode((string)$value);
                }
            }
            if (!empty($cookies)) {
                $cookieHeaders[] = implode('; ', $cookies);
            }
        }

        // Handle raw cookie header
        if (isset($cookieOptions['cookie_header']) && is_string($cookieOptions['cookie_header'])) {
            $cookieHeaders[] = $cookieOptions['cookie_header'];
        }

        // Apply cookies to headers
        if (!empty($cookieHeaders)) {
            $existingHeaders = $curlOptions[CURLOPT_HTTPHEADER] ?? [];

            // Remove any existing Cookie headers
            $existingHeaders = array_filter($existingHeaders, function ($header) {
                return stripos($header, 'Cookie:') !== 0;
            });

            // Add new Cookie header
            $existingHeaders[] = 'Cookie: ' . implode('; ', $cookieHeaders);
            $curlOptions[CURLOPT_HTTPHEADER] = $existingHeaders;
        }
    }
}
