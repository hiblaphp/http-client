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
    /**
     * Named cookie jars.
     *
     * @var array<string, CookieJarInterface>
     */
    private array $cookieJars = [];

    /**
     * Paths to created cookie files for cleanup.
     *
     * @var array<string>
     */
    private array $createdCookieFiles = [];

    /**
     * The default cookie jar.
     */
    private ?CookieJarInterface $defaultCookieJar = null;

    /**
     * Whether to automatically manage cookie file cleanup.
     */
    private bool $autoManage;

    /**
     * Creates a new cookie manager.
     *
     * @param bool $autoManage Whether to automatically clean up created files
     */
    public function __construct(bool $autoManage = true)
    {
        $this->autoManage = $autoManage;
    }

    /**
     * Creates a new in-memory cookie jar.
     *
     * @param string $name Name for the jar
     * @return CookieJarInterface The created jar
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
     * Creates a new file-based cookie jar.
     *
     * @param string $filename Path to cookie file
     * @param bool $includeSessionCookies Whether to include session cookies
     * @param string $name Name for the jar
     * @return FileCookieJar The created jar
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
     * Gets a cookie jar by name.
     *
     * @param string $name Jar name
     * @return CookieJarInterface|null The jar or null if not found
     */
    public function getCookieJar(string $name = 'default'): ?CookieJarInterface
    {
        return $this->cookieJars[$name] ?? null;
    }

    /**
     * Sets the default cookie jar.
     *
     * @param CookieJarInterface $jar The jar to set as default
     * @return self
     */
    public function setDefaultCookieJar(CookieJarInterface $jar): self
    {
        $this->defaultCookieJar = $jar;

        return $this;
    }

    /**
     * Gets the default cookie jar, creating one if none exists.
     *
     * @return CookieJarInterface The default jar
     */
    public function getDefaultCookieJar(): CookieJarInterface
    {
        if ($this->defaultCookieJar === null) {
            $this->defaultCookieJar = $this->createCookieJar('default');
        }

        return $this->defaultCookieJar;
    }

    /**
     * Adds a cookie to a specific jar or the default jar.
     *
     * @param string $name Cookie name
     * @param string $value Cookie value
     * @param string|null $domain Cookie domain
     * @param string|null $path Cookie path
     * @param int|null $expires Expiration timestamp
     * @param bool $secure Whether cookie is secure
     * @param bool $httpOnly Whether cookie is HTTP only
     * @param string|null $sameSite SameSite attribute
     * @param string $jarName Name of jar to add to
     * @return self
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
     * Adds multiple cookies at once.
     *
     * @param array<string, string|array{value?: string, domain?: string, path?: string, expires?: int, secure?: bool, httpOnly?: bool, sameSite?: string}> $cookies Cookies to add
     * @param string $jarName Name of jar to add to
     * @return self
     */
    public function addCookies(array $cookies, string $jarName = 'default'): self
    {
        foreach ($cookies as $name => $config) {
            if (is_string($config)) {
                $this->addCookie($name, $config, null, '/', null, false, false, null, $jarName);
            } elseif (is_array($config)) {
                $value = $config['value'] ?? '';
                $domain = $config['domain'] ?? null;
                $path = $config['path'] ?? '/';
                $expires = $config['expires'] ?? null;
                $secure = $config['secure'] ?? false;
                $httpOnly = $config['httpOnly'] ?? false;
                $sameSite = $config['sameSite'] ?? null;
                
                $this->addCookie(
                    $name,
                    is_string($value) ? $value : '',
                    is_string($domain) ? $domain : null,
                    is_string($path) ? $path : '/',
                    is_int($expires) ? $expires : null,
                    is_bool($secure) ? $secure : false,
                    is_bool($httpOnly) ? $httpOnly : false,
                    is_string($sameSite) ? $sameSite : null,
                    $jarName
                );
            }
        }

        return $this;
    }

    /**
     * Configures a mock to set cookies via Set-Cookie headers.
     *
     * @param MockedRequest $mock The mock to configure
     * @param array<string, string|array{value?: string, domain?: string, path?: string, expires?: int, secure?: bool, httpOnly?: bool, sameSite?: string}> $cookies Cookies to set
     */
    public function mockSetCookies(MockedRequest $mock, array $cookies): void
    {
        foreach ($cookies as $name => $config) {
            if (is_string($config)) {
                $mock->addResponseHeader('Set-Cookie', "{$name}={$config}; Path=/");
            } elseif (is_array($config)) {
                $value = $config['value'] ?? '';
                $setCookieValue = $name . '=' . (is_string($value) ? $value : '');

                if (isset($config['path']) && is_string($config['path'])) {
                    $setCookieValue .= '; Path=' . $config['path'];
                }
                if (isset($config['domain']) && is_string($config['domain'])) {
                    $setCookieValue .= '; Domain=' . $config['domain'];
                }
                if (isset($config['expires']) && is_int($config['expires'])) {
                    $setCookieValue .= '; Expires=' . gmdate('D, d M Y H:i:s T', $config['expires']);
                }
                if (($config['secure'] ?? false) === true) {
                    $setCookieValue .= '; Secure';
                }
                if (($config['httpOnly'] ?? false) === true) {
                    $setCookieValue .= '; HttpOnly';
                }
                if (isset($config['sameSite']) && is_string($config['sameSite'])) {
                    $setCookieValue .= '; SameSite=' . $config['sameSite'];
                }

                $mock->addResponseHeader('Set-Cookie', $setCookieValue);
            }
        }
    }

    /**
     * Asserts that a cookie exists in a jar.
     *
     * @param string $name Cookie name
     * @param string $jarName Jar name
     * @throws MockAssertionException If assertion fails
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
     * Asserts that a cookie has a specific value.
     *
     * @param string $name Cookie name
     * @param string $expectedValue Expected value
     * @param string $jarName Jar name
     * @throws MockAssertionException If assertion fails
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
     * Asserts that a cookie was sent in a request.
     *
     * @param string $name Cookie name
     * @param array<int|string, mixed> $curlOptions cURL options from the request
     * @throws MockAssertionException If assertion fails
     */
    public function assertCookieSent(string $name, array $curlOptions): void
    {
        $cookieHeader = '';

        $httpHeaders = $curlOptions[CURLOPT_HTTPHEADER] ?? null;
        if (is_array($httpHeaders)) {
            foreach ($httpHeaders as $header) {
                if (!is_string($header)) {
                    continue;
                }
                if (str_starts_with(strtolower($header), 'cookie:')) {
                    $cookieHeader = substr($header, 7);
                    break;
                }
            }
        }

        if ($cookieHeader === '') {
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
     * Gets cookie count in a jar.
     *
     * @param string $jarName Jar name
     * @return int Cookie count
     */
    public function getCookieCount(string $jarName = 'default'): int
    {
        $jar = $this->getCookieJar($jarName);

        return $jar !== null ? count($jar->getAllCookies()) : 0;
    }

    /**
     * Clears all cookies from a jar.
     *
     * @param string $jarName Jar name
     * @return self
     */
    public function clearCookies(string $jarName = 'default'): self
    {
        $jar = $this->getCookieJar($jarName);
        if ($jar !== null) {
            $jar->clear();
        }

        return $this;
    }

    /**
     * Applies cookies from a jar to curl options.
     *
     * @param array<int|string, mixed> $curlOptions cURL options to modify
     * @param string $url Request URL
     * @param string $jarName Jar name
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

            $cookieHeaderExists = false;
            $httpHeaders = $curlOptions[CURLOPT_HTTPHEADER];
            if (is_array($httpHeaders)) {
                foreach ($httpHeaders as $key => $header) {
                    if (!is_string($header)) {
                        continue;
                    }
                    if (str_starts_with(strtolower($header), 'cookie:')) {
                        $httpHeaders[$key] = $header . '; ' . $cookieHeader;
                        $curlOptions[CURLOPT_HTTPHEADER] = $httpHeaders;
                        $cookieHeaderExists = true;
                        break;
                    }
                }
            }

            if (!$cookieHeaderExists) {
                if (!is_array($curlOptions[CURLOPT_HTTPHEADER])) {
                    $curlOptions[CURLOPT_HTTPHEADER] = [];
                }
                $curlOptions[CURLOPT_HTTPHEADER][] = 'Cookie: ' . $cookieHeader;
            }
        }
    }

    /**
     * Processes Set-Cookie headers from a response and updates the jar.
     *
     * @param array<string, string|array<string>> $headers Response headers
     * @param string $jarName Jar name
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
            if (!is_string($setCookieHeader)) {
                continue;
            }
            $cookie = Cookie::fromSetCookieHeader($setCookieHeader);
            if ($cookie !== null) {
                $jar->setCookie($cookie);
            }
        }
    }

    /**
     * Creates a temporary cookie file.
     *
     * @param string $prefix Filename prefix
     * @return string Path to created file
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
     * Cleans up all managed cookie files.
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
     * Gets debug information about all cookie jars.
     *
     * @return array<string, array{type: string, cookie_count: int, cookies: array<array{name: string, value: string, domain: string|null, path: string, expires: int|null, secure: bool, httpOnly: bool, sameSite: string|null, expired: bool}>}> Debug info
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
     * Applies cookies to curl options, honoring a custom jar or falling back to default.
     *
     * @param array<int|string, mixed> $curlOptions cURL options to modify
     * @param string $url Request URL
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

            $httpHeaders = $curlOptions[CURLOPT_HTTPHEADER];
            if (is_array($httpHeaders)) {
                foreach ($httpHeaders as $key => $header) {
                    if (!is_string($header)) {
                        continue;
                    }
                    if (str_starts_with(strtolower($header), 'cookie:')) {
                        $httpHeaders[$key] = $header . '; ' . $cookieHeader;
                        $curlOptions[CURLOPT_HTTPHEADER] = $httpHeaders;
                        return;
                    }
                }
            }

            if (!is_array($curlOptions[CURLOPT_HTTPHEADER])) {
                $curlOptions[CURLOPT_HTTPHEADER] = [];
            }
            $curlOptions[CURLOPT_HTTPHEADER][] = 'Cookie: ' . $cookieHeader;

            return;
        }

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

            $httpHeaders = $curlOptions[CURLOPT_HTTPHEADER];
            if (is_array($httpHeaders)) {
                foreach ($httpHeaders as $key => $header) {
                    if (!is_string($header)) {
                        continue;
                    }
                    if (str_starts_with(strtolower($header), 'cookie:')) {
                        $httpHeaders[$key] = $header . '; ' . $cookieHeader;
                        $curlOptions[CURLOPT_HTTPHEADER] = $httpHeaders;
                        return;
                    }
                }
            }

            if (!is_array($curlOptions[CURLOPT_HTTPHEADER])) {
                $curlOptions[CURLOPT_HTTPHEADER] = [];
            }
            $curlOptions[CURLOPT_HTTPHEADER][] = 'Cookie: ' . $cookieHeader;

            return;
        }

        $this->applyCookiesToCurlOptions($curlOptions, $url);
    }

    /**
     * Processes Set-Cookie headers and applies them to both default and custom jars.
     *
     * @param array<string, string|array<string>> $headers Response headers
     * @param array<int|string, mixed> $curlOptions cURL options
     * @param string $url Request URL
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

        if ($setCookieHeaders === []) {
            return;
        }

        $uri = new Uri($url);
        $requestDomain = $uri->getHost();

        foreach ($setCookieHeaders as $setCookieHeader) {
            if (!is_string($setCookieHeader)) {
                continue;
            }
            $cookie = Cookie::fromSetCookieHeader($setCookieHeader);
            if ($cookie === null) {
                continue;
            }

            if ($cookie->getDomain() === null && $requestDomain !== '') {
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