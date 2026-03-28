<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

use Hibla\HttpClient\Exceptions\HttpStreamException;
use Hibla\HttpClient\Interfaces\Cookie\CookieJarInterface;
use Hibla\HttpClient\Interfaces\EnhancedResponseInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Represents an HTTP response.
 *
 * This class provides an immutable, PSR-7 compatible representation of an HTTP response,
 * along with several convenient helper methods for inspecting the response status,
 * headers, and body.
 */
class Response extends Message implements EnhancedResponseInterface
{
    /**
     * @var array<int, string> Map of standard HTTP status code/reason phrases.
     */
    private const array PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-status',
        208 => 'Already Reported',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Time-out',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Large',
        415 => 'Unsupported Media Type',
        416 => 'Requested range not satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Time-out',
        505 => 'HTTP Version not supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        511 => 'Network Authentication Required',
    ];

    private int $statusCode;
    private string $reasonPhrase;
    private ?string $negotiatedHttpVersion = null;

    /**
     * Initializes a new Response instance.
     *
     * @param  string|StreamInterface  $body  The response body. Can be a string or a StreamInterface object.
     * @param  int  $status  The HTTP status code.
     * @param  array<string, string|string[]>  $headers  An associative array of response headers.
     *
     * @throws HttpStreamException
     */
    public function __construct($body = 'php://memory', int $status = 200, array $headers = [])
    {
        $this->statusCode = $status;
        $this->reasonPhrase = self::PHRASES[$status] ?? 'Unknown Status Code';

        if (! ($body instanceof StreamInterface)) {
            $resource = fopen('php://temp', 'r+');
            if ($resource === false) {
                throw new \RuntimeException('Unable to create temporary stream');
            }
            if (\is_string($body)) {
                if ($body !== '') {
                    $writeResult = fwrite($resource, $body);
                    if ($writeResult === false) {
                        fclose($resource);

                        throw new \RuntimeException('Unable to write to temporary stream');
                    }
                    rewind($resource);
                }
            }
            $body = new Stream($resource);
        }

        $this->body = $body;
        $this->setHeaders($headers);
    }

    /**
     * {@inheritdoc}
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * {@inheritdoc}
     */
    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \InvalidArgumentException For invalid status code arguments.
     */
    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        if ($code < 100 || $code >= 600) {
            throw new \InvalidArgumentException('Status code must be an integer value between 1xx and 5xx.');
        }

        $new = clone $this;
        $new->statusCode = $code;
        if ($reasonPhrase === '' && isset(self::PHRASES[$code])) {
            $reasonPhrase = self::PHRASES[$code];
        }
        $new->reasonPhrase = $reasonPhrase;

        return $new;
    }

    /**
     * Get cookies from Set-Cookie headers.
     *
     * @return Cookie[]
     */
    public function getCookies(): array
    {
        $setCookieHeaders = $this->getHeader('Set-Cookie');
        $cookies = [];

        foreach ($setCookieHeaders as $header) {
            $cookie = Cookie::fromSetCookieHeader($header);
            if ($cookie !== null) {
                $cookies[] = $cookie;
            }
        }

        return $cookies;
    }

    /**
     * Apply cookies from this response to a cookie jar.
     */
    public function applyCookiesToJar(CookieJarInterface $cookieJar): void
    {
        foreach ($this->getCookies() as $cookie) {
            $cookieJar->setCookie($cookie);
        }
    }

    /**
     * Get the response body as a string.
     *
     * @return string The full response body.
     */
    public function body(): string
    {
        return (string) $this->body;
    }

    /**
     * {@inheritdoc}
     */
    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    /**
     * Get the response body decoded from JSON.
     *
     * @param  string|null  $key  Optional dot-notation key to extract a specific value
     * @param  mixed  $default  Default value to return if key is not found or JSON decode fails
     * @return mixed The decoded JSON data, specific value, or default
     */
    public function json(?string $key = null, $default = null): mixed
    {
        $decoded = json_decode((string) $this->body, true);

        if (! \is_array($decoded)) {
            return $default;
        }

        if ($key === null) {
            return $decoded;
        }

        return $this->getValueByKey($decoded, $key, $default);
    }

    /**
     * Get the HTTP status code.
     *
     * @return int The status code.
     */
    public function status(): int
    {
        return $this->statusCode;
    }

    /**
     * Get all response headers.
     *
     * @return array<string, string> An associative array of header names to values.
     */
    public function headers(): array
    {
        $headers = [];
        foreach ($this->headers as $name => $values) {
            $headers[strtolower($name)] = \is_array($values) ? implode(', ', $values) : $values;
        }

        return $headers;
    }

    /**
     * Get a single response header by name.
     *
     * @param  string  $name  The case-insensitive header name.
     * @return string|null The header value, or null if the header does not exist.
     */
    public function header(string $name): ?string
    {
        $header = $this->getHeaderLine($name);

        return $header !== '' ? $header : null;
    }

    /**
     * Determine if the response has a successful status code (2xx).
     *
     * @return bool True if the status code is between 200 and 299.
     */
    public function successful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Determine if the response indicates a client or server error.
     *
     * @return bool True if the status code is 400 or greater.
     */
    public function failed(): bool
    {
        return ! $this->successful();
    }

    /**
     * Determine if the response has a client error status code (4xx).
     *
     * @return bool True if the status code is between 400 and 499.
     */
    public function clientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    /**
     * Determine if the response has a server error status code (5xx).
     *
     * @return bool True if the status code is 500 or greater.
     */
    public function serverError(): bool
    {
        return $this->statusCode >= 500;
    }

    /**
     * Get the negotiated HTTP version as a canonical string.
     *
     * Returns '1.0', '1.1', '2', or '3' — or null if the version
     * was never set (e.g. the request has not completed yet, or the
     * event loop did not report a version).
     */
    public function getHttpVersion(): ?string
    {
        return $this->negotiatedHttpVersion;
    }

    /**
     * @internal
     *
     * Set the negotiated HTTP version (called internally).
     * Normalizes the version string to a canonical form:
     *   '1.0', '1.1'  → kept as-is   (minor version is meaningful)
     *   '2', '2.0'    → '2'          (HTTP/2 has no minor version)
     *   '3', '3.0'    → '3'          (HTTP/3 has no minor version)
     */
    public function setHttpVersion(?string $version): void
    {
        if ($version === null) {
            $this->negotiatedHttpVersion = null;

            return;
        }

        $this->negotiatedHttpVersion = $this->normalizeHttpVersion($version);

        $this->protocol = $this->negotiatedHttpVersion;
    }

    /**
     * Get the HTTP version as a full protocol string (e.g. 'HTTP/2').
     *
     * Falls back to the PSR-7 protocol version when no negotiated
     * version has been recorded.
     */
    public function getHttpVersionString(): string
    {
        $version = $this->negotiatedHttpVersion ?? $this->protocol;

        return 'HTTP/' . $version;
    }

    /**
     * Get a value from an array using dot notation.
     * If a direct key match exists, it takes priority over dot notation.
     *
     * @param  array<mixed>  $array
     */
    protected function getValueByKey(array $array, string $key, mixed $default): mixed
    {
        if (\array_key_exists($key, $array)) {
            return $array[$key];
        }

        foreach (explode('.', $key) as $segment) {
            if (\is_array($array) && \array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return $default;
            }
        }

        return $array;
    }

    /**
     * Normalize a raw version string to a canonical form.
     *
     *   '1.0'          → '1.0'
     *   '1.1'          → '1.1'
     *   '2' or '2.0'   → '2'
     *   '3' or '3.0'   → '3'
     *
     * Any unrecognised value is returned unchanged so future versions
     * are handled gracefully without a code change here.
     */
    private function normalizeHttpVersion(string $version): string
    {
        return match ($version) {
            '2', '2.0' => '2',
            '3', '3.0' => '3',
            default => $version,
        };
    }
}
