<?php

namespace Hibla\Http;

/**
 * File-based persistent cookie jar implementation.
 */
class FileCookieJar extends CookieJar
{
    private string $filename;
    private bool $storeSessionCookies;

    /**
     * Creates a new file-based cookie jar.
     */
    public function __construct(string $filename, bool $storeSessionCookies = false)
    {
        $this->filename = $filename;
        $this->storeSessionCookies = $storeSessionCookies;
        $this->load();
    }

    /**
     * Saves cookies to file when the object is destroyed.
     */
    public function __destruct()
    {
        $this->save();
    }

    /**
     * Sets a cookie and saves to file.
     */
    public function setCookie(Cookie $cookie): void
    {
        parent::setCookie($cookie);
        $this->save();
    }

    /**
     * Clears all cookies and saves to file.
     */
    public function clear(): void
    {
        parent::clear();
        $this->save();
    }

    /**
     * Loads cookies from file.
     */
    private function load(): void
    {
        if (! file_exists($this->filename)) {
            return;
        }

        $content = file_get_contents($this->filename);
        if ($content === false) {
            return;
        }

        $data = json_decode($content, true);
        if (! is_array($data)) {
            return;
        }

        foreach ($data as $cookieData) {
            if (! is_array($cookieData)) {
                continue;
            }

            $name = isset($cookieData['name']) && is_string($cookieData['name']) ? $cookieData['name'] : '';
            $value = isset($cookieData['value']) && is_string($cookieData['value']) ? $cookieData['value'] : '';

            $expires = null;
            if (isset($cookieData['expires'])) {
                if (is_int($cookieData['expires'])) {
                    $expires = $cookieData['expires'];
                }
            }

            $domain = null;
            if (isset($cookieData['domain'])) {
                if (is_string($cookieData['domain'])) {
                    $domain = $cookieData['domain'];
                }
            }

            $path = null;
            if (isset($cookieData['path'])) {
                if (is_string($cookieData['path'])) {
                    $path = $cookieData['path'];
                }
            }

            $secure = isset($cookieData['secure']) && is_bool($cookieData['secure']) ? $cookieData['secure'] : false;
            $httpOnly = isset($cookieData['httpOnly']) && is_bool($cookieData['httpOnly']) ? $cookieData['httpOnly'] : false;

            $maxAge = null;
            if (isset($cookieData['maxAge'])) {
                if (is_int($cookieData['maxAge'])) {
                    $maxAge = $cookieData['maxAge'];
                }
            }

            $sameSite = null;
            if (isset($cookieData['sameSite'])) {
                if (is_string($cookieData['sameSite'])) {
                    $sameSite = $cookieData['sameSite'];
                }
            }

            $cookie = new Cookie($name, $value, $expires, $domain, $path, $secure, $httpOnly, $maxAge, $sameSite);

            if (! $cookie->isExpired()) {
                $this->cookies[] = $cookie;
            }
        }
    }

    /**
     * Saves cookies to file.
     */
    private function save(): void
    {
        $data = [];

        foreach ($this->cookies as $cookie) {
            // Skip session cookies if not storing them
            if (! $this->storeSessionCookies && $cookie->getExpires() === null && $cookie->getMaxAge() === null) {
                continue;
            }

            $data[] = [
                'name' => $cookie->getName(),
                'value' => $cookie->getValue(),
                'expires' => $cookie->getExpires(),
                'domain' => $cookie->getDomain(),
                'path' => $cookie->getPath(),
                'secure' => $cookie->isSecure(),
                'httpOnly' => $cookie->isHttpOnly(),
                'maxAge' => $cookie->getMaxAge(),
                'sameSite' => $cookie->getSameSite(),
            ];
        }

        $dir = dirname($this->filename);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->filename, json_encode($data, JSON_PRETTY_PRINT));
    }
}
