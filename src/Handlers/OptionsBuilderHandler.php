<?php

namespace Hibla\HttpClient\Handlers;

use Hibla\HttpClient\Interfaces\CookieJarInterface;
use Hibla\HttpClient\ProxyConfig;
use Hibla\HttpClient\RetryConfig;
use Hibla\HttpClient\Stream;
use Hibla\HttpClient\Uri;

class OptionsBuilderHandler
{
    /**
     * Compiles all configured options into a cURL options array.
     *
     * @param  string  $method  The HTTP method.
     * @param  string  $url  The target URL.
     * @param  array<string, array<string>>  $headers  The request headers.
     * @param  Stream  $body  The request body stream.
     * @param  int  $timeout  Total request timeout.
     * @param  int  $connectTimeout  Connection timeout.
     * @param  bool  $followRedirects  Whether to follow redirects.
     * @param  int  $maxRedirects  Maximum number of redirects.
     * @param  bool  $verifySSL  Whether to verify SSL certificates.
     * @param  string|null  $userAgent  The User-Agent string.
     * @param  string  $protocol  HTTP protocol version.
     * @param  CookieJarInterface|null  $cookieJar  Cookie jar for the request.
     * @param  CookieJarInterface|null  $handlerCookieJar  Fallback cookie jar from handler.
     * @param  ProxyConfig|null  $proxyConfig  Proxy configuration.
     * @param  array{0: string, 1: string, 2: string}|null  $auth  Authentication credentials [type, username, password].
     * @param  array<int|string, mixed>  $additionalOptions  Additional cURL or custom options.
     * @return array<int|string, mixed> The final cURL options array.
     */
    public function buildCurlOptions(
        string $method,
        string $url,
        array $headers,
        Stream $body,
        int $timeout,
        int $connectTimeout,
        bool $followRedirects,
        int $maxRedirects,
        bool $verifySSL,
        ?string $userAgent,
        string $protocol,
        ?CookieJarInterface $cookieJar,
        ?CookieJarInterface $handlerCookieJar,
        ?ProxyConfig $proxyConfig,
        ?array $auth,
        array $additionalOptions
    ): array {
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS => $maxRedirects,
            CURLOPT_SSL_VERIFYPEER => $verifySSL,
            CURLOPT_SSL_VERIFYHOST => $verifySSL ? 2 : 0,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
        ];

        $options[CURLOPT_HTTP_VERSION] = $this->resolveHttpVersion($protocol);

        $effectiveCookieJar = $cookieJar ?? $handlerCookieJar;

        if ($effectiveCookieJar !== null) {
            $headers = $this->mergeCookieHeader($url, $headers, $effectiveCookieJar);
        }

        if ($proxyConfig !== null) {
            $this->addProxyOptions($options, $proxyConfig);
        }

        if (strtoupper($method) === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }

        $this->addHeaderOptions($options, $headers);

        $stringKeyOptions = array_filter(
            $additionalOptions,
            fn ($key) => is_string($key),
            ARRAY_FILTER_USE_KEY
        );

        $this->addBodyOptions($options, $body, $stringKeyOptions);

        $this->addAuthenticationOptions($options, $auth);

        if ($effectiveCookieJar !== null) {
            $options['_cookie_jar'] = $effectiveCookieJar;
        }

        // Merge additional options (integer keys only for cURL options)
        foreach ($additionalOptions as $key => $value) {
            if (is_int($key)) {
                $options[$key] = $value;
            }
        }

        return $options;
    }

    /**
     * Builds a high-level "fetch" style options array.
     *
     * @param  string  $method  The HTTP method.
     * @param  array<string, array<string>>  $headers  The request headers.
     * @param  Stream  $body  The request body stream.
     * @param  int  $timeout  Total request timeout.
     * @param  int  $connectTimeout  Connection timeout.
     * @param  bool  $followRedirects  Whether to follow redirects.
     * @param  int  $maxRedirects  Maximum number of redirects.
     * @param  bool  $verifySSL  Whether to verify SSL certificates.
     * @param  string|null  $userAgent  The User-Agent string.
     * @param  array{0: string, 1: string, 2: string}|null  $auth  Authentication credentials.
     * @param  RetryConfig|null  $retryConfig  Retry configuration.
     * @return array<string, mixed> The fetch options array.
     */
    public function buildFetchOptions(
        string $method,
        array $headers,
        Stream $body,
        int $timeout,
        int $connectTimeout,
        bool $followRedirects,
        int $maxRedirects,
        bool $verifySSL,
        ?string $userAgent,
        ?array $auth,
        ?RetryConfig $retryConfig
    ): array {
        $options = [
            'method' => $method,
            'headers' => [],
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
            'follow_redirects' => $followRedirects,
            'max_redirects' => $maxRedirects,
            'verify_ssl' => $verifySSL,
            'user_agent' => $userAgent,
            'auth' => [],
        ];

        if ($retryConfig !== null) {
            $options['retry'] = $retryConfig;
        }

        foreach ($headers as $name => $values) {
            $options['headers'][$name] = implode(', ', $values);
        }

        if ($auth !== null) {
            [$type, $username, $password] = $auth;
            if ($type === 'basic') {
                $options['auth']['basic'] = ['username' => $username, 'password' => $password];
            } elseif ($type === 'digest') {
                $options['auth']['digest'] = ['username' => $username, 'password' => $password];
            }
        }

        if ($body->getSize() > 0) {
            $options['body'] = (string) $body;
        }

        return $options;
    }

    /**
     * Resolve HTTP version to cURL constant.
     */
    private function resolveHttpVersion(string $protocol): int
    {
        return match ($protocol) {
            '2.0', '2' => CURL_HTTP_VERSION_2TLS,
            '3.0', '3' => defined('CURL_HTTP_VERSION_3')
                ? CURL_HTTP_VERSION_3
                : CURL_HTTP_VERSION_1_1,
            '1.0' => CURL_HTTP_VERSION_1_0,
            default => CURL_HTTP_VERSION_2TLS,
        };
    }

    /**
     * Merge cookie header from cookie jar with existing headers.
     *
     * @param  string  $url  The target URL.
     * @param  array<string, array<string>>  $headers  Existing headers.
     * @param  CookieJarInterface  $cookieJar  The cookie jar.
     * @return array<string, array<string>> Updated headers.
     */
    private function mergeCookieHeader(string $url, array $headers, CookieJarInterface $cookieJar): array
    {
        $uri = new Uri($url);
        $cookieHeader = $cookieJar->getCookieHeader(
            $uri->getHost(),
            $uri->getPath() !== '' ? $uri->getPath() : '/',
            $uri->getScheme() === 'https'
        );

        if ($cookieHeader === '') {
            return $headers;
        }

        $existingCookie = '';
        $lowerHeaders = array_change_key_case($headers, CASE_LOWER);

        if (isset($lowerHeaders['cookie'])) {
            $existingCookie = implode('; ', $lowerHeaders['cookie']);
            // Remove old Cookie header (case-insensitive)
            foreach ($headers as $name => $value) {
                if (strtolower($name) === 'cookie') {
                    unset($headers[$name]);

                    break;
                }
            }
        }

        $newCookieValue = $existingCookie !== ''
            ? $existingCookie . '; ' . $cookieHeader
            : $cookieHeader;

        $headers['Cookie'] = [$newCookieValue];

        return $headers;
    }

    /**
     * Adds configured proxy settings to the cURL options.
     *
     * @param  array<int, mixed>  &$options  The cURL options array passed by reference.
     * @param  ProxyConfig  $proxyConfig  The proxy configuration.
     */
    private function addProxyOptions(array &$options, ProxyConfig $proxyConfig): void
    {
        $options[CURLOPT_PROXY] = $proxyConfig->host . ':' . $proxyConfig->port;
        $options[CURLOPT_PROXYTYPE] = $proxyConfig->getCurlProxyType();

        if ($proxyConfig->username !== null) {
            $proxyAuth = $proxyConfig->username;
            if ($proxyConfig->password !== null) {
                $proxyAuth .= ':' . $proxyConfig->password;
            }
            $options[CURLOPT_PROXYUSERPWD] = $proxyAuth;
        }

        if (in_array($proxyConfig->type, ['socks4', 'socks5'], true)) {
            $options[CURLOPT_HTTPPROXYTUNNEL] = false;
        } else {
            $options[CURLOPT_HTTPPROXYTUNNEL] = true;
        }
    }

    /**
     * Adds configured headers to the cURL options.
     *
     * @param  array<int, mixed>  &$options  The cURL options array passed by reference.
     * @param  array<string, array<string>>  $headers  The headers to add.
     */
    private function addHeaderOptions(array &$options, array $headers): void
    {
        if (count($headers) > 0) {
            $headerStrings = [];
            foreach ($headers as $name => $values) {
                $headerStrings[] = "{$name}: " . implode(', ', $values);
            }
            $options[CURLOPT_HTTPHEADER] = $headerStrings;
        }
    }

    /**
     * Adds the configured body to the cURL options.
     *
     * @param  array<int, mixed>  &$options  The cURL options array passed by reference.
     * @param  Stream  $body  The request body stream.
     * @param  array<string, mixed>  $additionalOptions  Additional options that may contain multipart data.
     */
    private function addBodyOptions(array &$options, Stream $body, array $additionalOptions): void
    {
        if (isset($additionalOptions['multipart'])) {
            $options[CURLOPT_POSTFIELDS] = $additionalOptions['multipart'];
        } elseif ($body->getSize() > 0) {
            $options[CURLOPT_POSTFIELDS] = (string) $body;
        }
    }

    /**
     * Adds configured authentication details to the cURL options.
     *
     * @param  array<int, mixed>  &$options  The cURL options array passed by reference.
     * @param  array{0: string, 1: string, 2: string}|null  $auth  Authentication configuration [type, username, password].
     */
    private function addAuthenticationOptions(array &$options, ?array $auth): void
    {
        if ($auth === null) {
            return;
        }

        [$type, $username, $password] = $auth;

        if ($type === 'basic') {
            $options[CURLOPT_USERPWD] = "{$username}:{$password}";
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        } elseif ($type === 'digest') {
            $options[CURLOPT_USERPWD] = "{$username}:{$password}";
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_DIGEST;
        }
    }
}
