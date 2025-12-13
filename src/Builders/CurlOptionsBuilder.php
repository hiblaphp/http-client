<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Builders;

use Hibla\HttpClient\ClientOptions;
use Hibla\HttpClient\Interfaces\CookieJarInterface;
use Hibla\HttpClient\Interfaces\TransportOptionsBuilderInterface;
use Hibla\HttpClient\ProxyConfig;
use Hibla\HttpClient\Uri;
use Psr\Http\Message\StreamInterface;

/**
 * Builds cURL-specific options from ClientOptions.
 * 
 * @implements TransportOptionsBuilderInterface<array<int|string, mixed>>
 */
class CurlOptionsBuilder implements TransportOptionsBuilderInterface
{
    /**
     * @return array<int|string, mixed>
     */
    public function build(ClientOptions $options): array
    {
        $curlOptions = [
            CURLOPT_URL => $options->url,
            CURLOPT_CUSTOMREQUEST => $options->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $options->timeout,
            CURLOPT_CONNECTTIMEOUT => $options->connectTimeout,
            CURLOPT_FOLLOWLOCATION => $options->followRedirects,
            CURLOPT_MAXREDIRS => $options->maxRedirects,
            CURLOPT_SSL_VERIFYPEER => $options->verifySSL,
            CURLOPT_SSL_VERIFYHOST => $options->verifySSL ? 2 : 0,
            CURLOPT_USERAGENT => $options->userAgent,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
        ];

        $curlOptions[CURLOPT_HTTP_VERSION] = $this->resolveHttpVersion($options->protocol);

        $headers = $options->headers;

        if ($options->cookieJar !== null) {
            $headers = $this->mergeCookieHeader($options->url, $headers, $options->cookieJar);
        }

        if ($options->proxyConfig !== null) {
            $this->addProxyOptions($curlOptions, $options->proxyConfig);
        }

        if (strtoupper($options->method) === 'HEAD') {
            $curlOptions[CURLOPT_NOBODY] = true;
        }

        $this->addHeaderOptions($curlOptions, $headers);

        $stringKeyOptions = array_filter(
            $options->additionalOptions,
            fn($key) => \is_string($key),
            ARRAY_FILTER_USE_KEY
        );

        $this->addBodyOptions($curlOptions, $options->body, $stringKeyOptions);
        $this->addAuthenticationOptions($curlOptions, $options->auth);

        if ($options->cookieJar !== null) {
            $curlOptions['_cookie_jar'] = $options->cookieJar;
        }

        foreach ($options->additionalOptions as $key => $value) {
            if (\is_int($key)) {
                $curlOptions[$key] = $value;
            }
        }

        return $curlOptions;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function buildForStreaming(ClientOptions $options): array
    {
        $curlOptions = $this->build($options);

        unset($curlOptions[CURLOPT_HEADER]);

        return $curlOptions;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function buildForDownload(ClientOptions $options, string $destination): array
    {
        $curlOptions = $this->build($options);

        unset($curlOptions[CURLOPT_HEADER]);

        $curlOptions['_destination'] = $destination;

        return $curlOptions;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function buildForSSE(ClientOptions $options): array
    {
        $curlOptions = $this->build($options);

        unset($curlOptions[CURLOPT_HEADER]);

        $existingHeaders = $curlOptions[CURLOPT_HTTPHEADER] ?? [];
        if (!\is_array($existingHeaders)) {
            $existingHeaders = [];
        }

        $sseHeaders = [
            'Accept: text/event-stream',
            'Cache-Control: no-cache',
            'Connection: keep-alive'
        ];

        $curlOptions[CURLOPT_HTTPHEADER] = array_merge($existingHeaders, $sseHeaders);

        return $curlOptions;
    }

    private function resolveHttpVersion(string $protocol): int
    {
        return match ($protocol) {
            '2.0', '2' => CURL_HTTP_VERSION_2TLS,
            '3.0', '3' => \defined('CURL_HTTP_VERSION_3')
                ? CURL_HTTP_VERSION_3
                : CURL_HTTP_VERSION_1_1,
            '1.0' => CURL_HTTP_VERSION_1_0,
            '1.1' => CURL_HTTP_VERSION_1_1,
            default => CURL_HTTP_VERSION_2TLS,
        };
    }

    /**
     * @param array<string, array<string>> $headers
     * @return array<string, array<string>>
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
     * @param array<int|string, mixed> $options
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

        if (\in_array($proxyConfig->type, ['socks4', 'socks5'], true)) {
            $options[CURLOPT_HTTPPROXYTUNNEL] = false;
        } else {
            $options[CURLOPT_HTTPPROXYTUNNEL] = true;
        }
    }

    /**
     * @param array<int|string, mixed> $options
     * @param array<string, array<string>> $headers
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
     * @param array<int|string, mixed> $options
     * @param array<string, mixed> $additionalOptions
     */
    private function addBodyOptions(array &$options, StreamInterface $body, array $additionalOptions): void
    {
        if (isset($additionalOptions['multipart'])) {
            $options[CURLOPT_POSTFIELDS] = $additionalOptions['multipart'];
        } elseif ($body->getSize() > 0) {
            $options[CURLOPT_POSTFIELDS] = (string) $body;
        }
    }

    /**
     * @param array<int|string, mixed> $options
     * @param array{0: string, 1: string, 2: string}|null $auth
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
