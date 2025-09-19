<?php

namespace Hibla\Http\Testing\Utilities;

use Hibla\Http\Cookie;
use Hibla\Http\CookieJar;
use Hibla\Http\FileCookieJar;
use Hibla\Http\Interfaces\CookieJarInterface;
use Hibla\Http\Testing\Exceptions\MockAssertionException;
use Hibla\Http\Testing\MockedRequest;
use Hibla\Http\Uri;

/**
 * Comprehensive cookie testing service for HTTP testing scenarios.
 */
class CookieManager
{
    /** @var array<string, CookieJarInterface> */
    private array $cookieJars = [];

    /** @var array<string> */
    private array $createdCookieFiles = [];

    private ?CookieJarInterface $defaultCookieJar = null;

    private bool $autoManage;

    public function __construct(bool $autoManage = true)
    {
        $this->autoManage = $autoManage;
    }

    /**
     * Create a new in-memory cookie jar.
     */
    public function createCookieJar(string $name = 'default'): CookieJarInterface
    {
        $jar = new CookieJar;
        $this->cookieJars[$name] = $jar;

        if ($name === 'default' || $this->defaultCookieJar === null) {
            $this->defaultCookieJar = $jar;
        }

        return $jar;
    }

    /**
     * Create a new file-based cookie jar.
     */
    public function createFileCookieJar(string $filename, bool $includeSessionCookies = true, string $name = 'default'): FileCookieJar
    {
        $jar = new FileCookieJar($filename, $includeSessionCookies);
        $this->cookieJars[$name] = $jar;

        if ($this->autoManage) {
            $this->createdCookieFiles[] = $filename;
        }

        if ($name === 'default' || $this->defaultCookieJar === null) {
            $this->defaultCookieJar = $jar;
        }

        return $jar;
    }

    /**
     * Get a cookie jar by name.
     */
    public function getCookieJar(string $name = 'default'): ?CookieJarInterface
    {
        return $this->cookieJars[$name] ?? null;
    }

    /**
     * Set the default cookie jar.
     */
    public function setDefaultCookieJar(CookieJarInterface $jar): self
    {
        $this->defaultCookieJar = $jar;

        return $this;
    }

    /**
     * Get the default cookie jar, creating one if none exists.
     */
    public function getDefaultCookieJar(): CookieJarInterface
    {
        if ($this->defaultCookieJar === null) {
            $this->defaultCookieJar = $this->createCookieJar('default');
        }

        return $this->defaultCookieJar;
    }

    /**
     * Add a cookie to a specific jar or the default jar.
     */
    public function addCookie(
        string $name,
        string $value,
        ?string $domain = null,
        ?string $path = '/',
        ?int $expires = null,
        bool $secure = false,
        bool $httpOnly = false,
        ?string $sameSite = null,
        string $jarName = 'default'
    ): self {
        $jar = $this->getCookieJar($jarName) ?? $this->createCookieJar($jarName);

        $cookie = new Cookie(
            $name,
            $value,
            $expires,
            $domain,
            $path,
            $secure,
            $httpOnly,
            null,
            $sameSite
        );

        $jar->setCookie($cookie);

        return $this;
    }

    /**
     * Add multiple cookies at once.
     */
    public function addCookies(array $cookies, string $jarName = 'default'): self
    {
        foreach ($cookies as $name => $config) {
            if (is_string($config)) {
                $this->addCookie($name, $config, null, '/', null, false, false, null, $jarName);
            } elseif (is_array($config)) {
                $this->addCookie(
                    $name,
                    $config['value'] ?? '',
                    $config['domain'] ?? null,
                    $config['path'] ?? '/',
                    $config['expires'] ?? null,
                    $config['secure'] ?? false,
                    $config['httpOnly'] ?? false,
                    $config['sameSite'] ?? null,
                    $jarName
                );
            }
        }

        return $this;
    }

    /**
     * Configure a mock to set cookies via Set-Cookie headers.
     */
    public function mockSetCookies(MockedRequest $mock, array $cookies): void
    {
        foreach ($cookies as $name => $config) {
            if (is_string($config)) {
                $mock->addResponseHeader('Set-Cookie', "{$name}={$config}; Path=/");
            } elseif (is_array($config)) {
                $setCookieValue = $name.'='.($config['value'] ?? '');

                if (isset($config['path'])) {
                    $setCookieValue .= '; Path='.$config['path'];
                }
                if (isset($config['domain'])) {
                    $setCookieValue .= '; Domain='.$config['domain'];
                }
                if (isset($config['expires'])) {
                    $setCookieValue .= '; Expires='.gmdate('D, d M Y H:i:s T', $config['expires']);
                }
                if ($config['secure'] ?? false) {
                    $setCookieValue .= '; Secure';
                }
                if ($config['httpOnly'] ?? false) {
                    $setCookieValue .= '; HttpOnly';
                }
                if (isset($config['sameSite'])) {
                    $setCookieValue .= '; SameSite='.$config['sameSite'];
                }

                $mock->addResponseHeader('Set-Cookie', $setCookieValue);
            }
        }
    }

    /**
     * Assert that a cookie exists in a jar.
     */
    public function assertCookieExists(string $name, string $jarName = 'default'): void
    {
        $jar = $this->getCookieJar($jarName);
        if ($jar === null) {
            throw new MockAssertionException("Cookie jar '{$jarName}' not found");
        }

        foreach ($jar->getAllCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return;
            }
        }

        throw new MockAssertionException("Cookie '{$name}' not found in jar '{$jarName}'");
    }

    /**
     * Assert that a cookie has a specific value.
     */
    public function assertCookieValue(string $name, string $expectedValue, string $jarName = 'default'): void
    {
        $jar = $this->getCookieJar($jarName);
        if ($jar === null) {
            throw new MockAssertionException("Cookie jar '{$jarName}' not found");
        }

        foreach ($jar->getAllCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                if ($cookie->getValue() === $expectedValue) {
                    return;
                }

                throw new MockAssertionException("Cookie '{$name}' has value '{$cookie->getValue()}', expected '{$expectedValue}'");
            }
        }

        throw new MockAssertionException("Cookie '{$name}' not found in jar '{$jarName}'");
    }

    /**
     * Assert that a cookie was sent in a request.
     */
    public function assertCookieSent(string $name, array $curlOptions): void
    {
        $cookieHeader = '';

        if (isset($curlOptions[CURLOPT_HTTPHEADER])) {
            foreach ($curlOptions[CURLOPT_HTTPHEADER] as $header) {
                if (str_starts_with(strtolower($header), 'cookie:')) {
                    $cookieHeader = substr($header, 7);

                    break;
                }
            }
        }

        if (empty($cookieHeader)) {
            throw new MockAssertionException('No Cookie header found in request');
        }

        $cookies = [];
        foreach (explode(';', $cookieHeader) as $cookie) {
            $parts = explode('=', trim($cookie), 2);
            if (count($parts) === 2) {
                $cookies[trim($parts[0])] = trim($parts[1]);
            }
        }

        if (! isset($cookies[$name])) {
            throw new MockAssertionException("Cookie '{$name}' was not sent in request. Sent cookies: ".implode(', ', array_keys($cookies)));
        }
    }

    /**
     * Get cookie count in a jar.
     */
    public function getCookieCount(string $jarName = 'default'): int
    {
        $jar = $this->getCookieJar($jarName);

        return $jar ? count($jar->getAllCookies()) : 0;
    }

    /**
     * Clear all cookies from a jar.
     */
    public function clearCookies(string $jarName = 'default'): self
    {
        $jar = $this->getCookieJar($jarName);
        if ($jar) {
            $jar->clear();
        }

        return $this;
    }

    /**
     * Apply cookies from a jar to curl options.
     */
    public function applyCookiesToCurlOptions(array &$curlOptions, string $url, string $jarName = 'default'): void
    {
        $jar = $this->getCookieJar($jarName);
        if ($jar === null) {
            return;
        }

        $uri = new Uri($url);
        $cookieHeader = $jar->getCookieHeader(
            $uri->getHost(),
            $uri->getPath() !== '' ? $uri->getPath() : '/',
            $uri->getScheme() === 'https'
        );

        if ($cookieHeader !== '') {
            $curlOptions[CURLOPT_HTTPHEADER] = $curlOptions[CURLOPT_HTTPHEADER] ?? [];

            // Check if Cookie header already exists and append
            $cookieHeaderExists = false;
            foreach ($curlOptions[CURLOPT_HTTPHEADER] as &$header) {
                if (str_starts_with(strtolower($header), 'cookie:')) {
                    $header .= '; '.$cookieHeader;
                    $cookieHeaderExists = true;

                    break;
                }
            }

            if (! $cookieHeaderExists) {
                $curlOptions[CURLOPT_HTTPHEADER][] = 'Cookie: '.$cookieHeader;
            }
        }
    }

    /**
     * Process Set-Cookie headers from a response and update the jar.
     */
    public function processSetCookieHeaders(array $headers, string $jarName = 'default'): void
    {
        $jar = $this->getCookieJar($jarName);
        if ($jar === null) {
            return;
        }

        $setCookieHeaders = [];
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'set-cookie') {
                if (is_array($value)) {
                    $setCookieHeaders = array_merge($setCookieHeaders, $value);
                } else {
                    $setCookieHeaders[] = $value;
                }
            }
        }

        foreach ($setCookieHeaders as $setCookieHeader) {
            $cookie = Cookie::fromSetCookieHeader($setCookieHeader);
            if ($cookie !== null) {
                $jar->setCookie($cookie);
            }
        }
    }

    /**
     * Create a temporary cookie file.
     */
    public function createTempCookieFile(string $prefix = 'test_cookies_'): string
    {
        $filename = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.uniqid().'.json';

        if ($this->autoManage) {
            $this->createdCookieFiles[] = $filename;
        }

        return $filename;
    }

    /**
     * Clean up all managed cookie files.
     */
    public function cleanup(): void
    {
        foreach ($this->createdCookieFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        $this->createdCookieFiles = [];
        $this->cookieJars = [];
        $this->defaultCookieJar = null;
    }

    /**
     * Get debug information about all cookie jars.
     */
    public function getDebugInfo(): array
    {
        $info = [];

        foreach ($this->cookieJars as $name => $jar) {
            $cookies = [];
            foreach ($jar->getAllCookies() as $cookie) {
                $cookies[] = [
                    'name' => $cookie->getName(),
                    'value' => $cookie->getValue(),
                    'domain' => $cookie->getDomain(),
                    'path' => $cookie->getPath(),
                    'expires' => $cookie->getExpires(),
                    'secure' => $cookie->isSecure(),
                    'httpOnly' => $cookie->isHttpOnly(),
                    'sameSite' => $cookie->getSameSite(),
                    'expired' => $cookie->isExpired(),
                ];
            }

            $info[$name] = [
                'type' => $jar instanceof FileCookieJar ? 'file' : 'memory',
                'cookie_count' => count($cookies),
                'cookies' => $cookies,
            ];
        }

        return $info;
    }

    /**
     * Apply cookies to curl options, honoring a custom jar in $curlOptions['_cookie_jar'] or falling back to the default jar.
     */
    public function applyCookiesForRequestOptions(array &$curlOptions, string $url): void
    {
        $jarOption = $curlOptions['_cookie_jar'] ?? null;

        if ($jarOption instanceof CookieJarInterface) {
            $uri = new Uri($url);
            $cookieHeader = $jarOption->getCookieHeader(
                $uri->getHost(),
                $uri->getPath() !== '' ? $uri->getPath() : '/',
                $uri->getScheme() === 'https'
            );

            if ($cookieHeader === '') {
                return;
            }

            $curlOptions[CURLOPT_HTTPHEADER] = $curlOptions[CURLOPT_HTTPHEADER] ?? [];

            foreach ($curlOptions[CURLOPT_HTTPHEADER] as &$header) {
                if (str_starts_with(strtolower($header), 'cookie:')) {
                    $header .= '; '.$cookieHeader;

                    return;
                }
            }

            $curlOptions[CURLOPT_HTTPHEADER][] = 'Cookie: '.$cookieHeader;

            return;
        }

        // If jar name provided, use stored jar if available
        if (is_string($jarOption)) {
            $jar = $this->getCookieJar($jarOption);
            if ($jar === null) {
                return;
            }

            $uri = new Uri($url);
            $cookieHeader = $jar->getCookieHeader(
                $uri->getHost(),
                $uri->getPath() !== '' ? $uri->getPath() : '/',
                $uri->getScheme() === 'https'
            );

            if ($cookieHeader === '') {
                return;
            }

            $curlOptions[CURLOPT_HTTPHEADER] = $curlOptions[CURLOPT_HTTPHEADER] ?? [];

            foreach ($curlOptions[CURLOPT_HTTPHEADER] as &$header) {
                if (str_starts_with(strtolower($header), 'cookie:')) {
                    $header .= '; '.$cookieHeader;

                    return;
                }
            }

            $curlOptions[CURLOPT_HTTPHEADER][] = 'Cookie: '.$cookieHeader;

            return;
        }

        $this->applyCookiesToCurlOptions($curlOptions, $url);
    }

    /**
     * Process Set-Cookie headers and apply them to both the shared manager (default jar)
     * and to a custom jar passed in $curlOptions['_cookie_jar'] (instance or jar name).
     *
     * Keeps the same domain-inference behavior as before: if the parsed cookie lacks a domain,
     * it will be set to the request host (if available).
     */
    public function processResponseCookiesForOptions(array $headers, array $curlOptions, string $url): void
    {
        $this->processSetCookieHeaders($headers, 'default');

        $jarOption = $curlOptions['_cookie_jar'] ?? null;
        if ($jarOption === null) {
            return;
        }

        $customJar = null;
        if ($jarOption instanceof CookieJarInterface) {
            $customJar = $jarOption;
        } elseif (is_string($jarOption)) {
            $customJar = $this->getCookieJar($jarOption);
        }

        if ($customJar === null) {
            return;
        }

        $setCookieHeaders = [];
        foreach ($headers as $name => $value) {
            if (strtolower($name) !== 'set-cookie') {
                continue;
            }
            if (is_array($value)) {
                $setCookieHeaders = array_merge($setCookieHeaders, $value);

                continue;
            }
            $setCookieHeaders[] = $value;
        }

        if (empty($setCookieHeaders)) {
            return;
        }

        $uri = new Uri($url);
        $requestDomain = $uri->getHost() ?? '';

        foreach ($setCookieHeaders as $setCookieHeader) {
            $cookie = Cookie::fromSetCookieHeader($setCookieHeader);
            if ($cookie === null) {
                continue;
            }

            if (empty($cookie->getDomain()) && $requestDomain !== '') {
                $cookie = new Cookie(
                    $cookie->getName(),
                    $cookie->getValue(),
                    $cookie->getExpires(),
                    $requestDomain,
                    $cookie->getPath(),
                    $cookie->isSecure(),
                    $cookie->isHttpOnly(),
                    $cookie->getMaxAge(),
                    $cookie->getSameSite()
                );
            }

            $customJar->setCookie($cookie);
        }
    }
}
