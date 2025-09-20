<?php

namespace Hibla\Http\Traits;

use Hibla\Http\Uri;

trait CurlOptionsTrait
{
    /**
     * Compiles all configured options into a cURL options array.
     *
     * @param  string  $method  The HTTP method.
     * @param  string  $url  The target URL.
     * @return array<int, mixed> The final cURL options array.
     */
    private function buildCurlOptions(string $method, string $url): array
    {
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => $this->followRedirects,
            CURLOPT_MAXREDIRS => $this->maxRedirects,
            CURLOPT_SSL_VERIFYPEER => $this->verifySSL,
            CURLOPT_SSL_VERIFYHOST => $this->verifySSL ? 2 : 0,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
        ];

        $options[CURLOPT_HTTP_VERSION] = match ($this->protocol) {
            '2.0', '2' => CURL_HTTP_VERSION_2TLS,
            '3.0', '3' => defined('CURL_HTTP_VERSION_3')
                ? CURL_HTTP_VERSION_3
                : CURL_HTTP_VERSION_1_1,
            '1.0' => CURL_HTTP_VERSION_1_0,
            default => CURL_HTTP_VERSION_1_1,
        };

        $effectiveCookieJar = $this->cookieJar ?? $this->handler->getCookieJar();

        if ($effectiveCookieJar !== null) {
            $uri = new Uri($url);
            $cookieHeader = $effectiveCookieJar->getCookieHeader(
                $uri->getHost(),
                $uri->getPath() !== '' ? $uri->getPath() : '/',
                $uri->getScheme() === 'https'
            );

            if ($cookieHeader !== '') {
                $existingCookies = $this->getHeaderLine('Cookie');
                if ($existingCookies !== '') {
                    $this->headers = $this->withHeader('Cookie', $existingCookies . '; ' . $cookieHeader)->getHeaders();
                } else {
                    $this->headers = $this->withHeader('Cookie', $cookieHeader)->getHeaders();
                }
            }
        }

        $this->addProxyOptions($options);

        if (strtoupper($method) === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }

        $this->addHeaderOptions($options);
        $this->addBodyOptions($options);
        $this->addAuthenticationOptions($options);

        if ($effectiveCookieJar !== null) {
            $options['_cookie_jar'] = $effectiveCookieJar;
        }

        foreach ($this->options as $key => $value) {
            if (is_int($key)) {
                $options[$key] = $value;
            }
        }

        return $options;
    }

    /**
     * Builds a high-level "fetch" style options array from the builder's state.
     *
     * @param  string  $method  The HTTP method.
     * @return array<string, mixed> The fetch options array.
     */
    private function buildFetchOptions(string $method): array
    {
        $options = [
            'method' => $method,
            'headers' => [],
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'follow_redirects' => $this->followRedirects,
            'max_redirects' => $this->maxRedirects,
            'verify_ssl' => $this->verifySSL,
            'user_agent' => $this->userAgent,
            'auth' => [],
        ];

        if ($this->retryConfig) {
            $options['retry'] = $this->retryConfig;
        }

        foreach ($this->headers as $name => $values) {
            $options['headers'][$name] = implode(', ', $values);
        }

        if ($this->auth !== null) {
            [$type, $username, $password] = $this->auth;
            if ($type === 'basic') {
                $options['auth']['basic'] = ['username' => $username, 'password' => $password];
            }
        }

        if ($this->body->getSize() > 0) {
            $options['body'] = (string) $this->body;
        }

        return $options;
    }

    /**
     * Adds configured proxy settings to the cURL options.
     *
     * @param  array<int, mixed>  &$options  The cURL options array passed by reference.
     */
    private function addProxyOptions(array &$options): void
    {
        if ($this->proxyConfig === null) {
            return;
        }

        $options[CURLOPT_PROXY] = $this->proxyConfig->host . ':' . $this->proxyConfig->port;
        $options[CURLOPT_PROXYTYPE] = $this->proxyConfig->getCurlProxyType();

        if ($this->proxyConfig->username !== null) {
            $proxyAuth = $this->proxyConfig->username;
            if ($this->proxyConfig->password !== null) {
                $proxyAuth .= ':' . $this->proxyConfig->password;
            }
            $options[CURLOPT_PROXYUSERPWD] = $proxyAuth;
        }

        if (in_array($this->proxyConfig->type, ['socks4', 'socks5'])) {
            $options[CURLOPT_HTTPPROXYTUNNEL] = false;
        } else {
            $options[CURLOPT_HTTPPROXYTUNNEL] = true;
        }
    }

    /**
     * Adds configured headers to the cURL options.
     *
     * @param  array<int, mixed>  &$options  The cURL options array passed by reference.
     */
    private function addHeaderOptions(array &$options): void
    {
        if (count($this->headers) > 0) {
            $headerStrings = [];
            foreach ($this->headers as $name => $value) {
                $headerStrings[] = "{$name}: " . implode(', ', $value);
            }
            $options[CURLOPT_HTTPHEADER] = $headerStrings;
        }
    }

    /**
     * Adds the configured body to the cURL options.
     *
     * @param  array<int, mixed>  &$options  The cURL options array passed by reference.
     */
    private function addBodyOptions(array &$options): void
    {
        if (isset($this->options['multipart'])) {
            $options[CURLOPT_POSTFIELDS] = $this->options['multipart'];
        } elseif ($this->body->getSize() > 0) {
            $options[CURLOPT_POSTFIELDS] = (string) $this->body;
        }
    }

    /**
     * Adds configured authentication details to the cURL options.
     *
     * @param  array<int, mixed>  &$options  The cURL options array passed by reference.
     */
    private function addAuthenticationOptions(array &$options): void
    {
        if ($this->auth !== null) {
            [$type, $username, $password] = $this->auth;
            if ($type === 'basic') {
                $options[CURLOPT_USERPWD] = "{$username}:{$password}";
                $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            }
        }
    }
}