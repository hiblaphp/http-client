<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

use Hibla\HttpClient\Exceptions\HttpStreamException;
use Hibla\HttpClient\Interfaces\Cookie\CookieJarInterface;
use Hibla\HttpClient\Interfaces\EnhancedResponseInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Immutable, PSR-7 compatible HTTP response with convenience inspection methods.
 */
class Response extends Message implements EnhancedResponseInterface
{
    /**
     * @var array<int, string> Map of standard HTTP status codes to reason phrases.
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
     * @param string|StreamInterface $body The response body as a string or stream.
     * @param int $status The HTTP status code.
     * @param array<string, string|string[]> $headers  Response headers.
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
            if (\is_string($body) && $body !== '') {
                $writeResult = fwrite($resource, $body);
                if ($writeResult === false) {
                    fclose($resource);
                    throw new \RuntimeException('Unable to write to temporary stream');
                }
                rewind($resource);
            }
            $body = new Stream($resource);
        }

        $this->body = $body;
        $this->setHeaders($headers);
    }

    /**
     * @inheritDoc
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @inheritDoc
     */
    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    /**
     * @inheritDoc
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
     * @inheritDoc
     */
    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    /**
     * @inheritDoc
     */
    public function body(): string
    {
        return (string) $this->body;
    }

    /**
     * @inheritDoc
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
     * @inheritDoc
     */
    public function status(): int
    {
        return $this->statusCode;
    }

    /**
     * @inheritDoc
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
     * @inheritDoc
     */
    public function header(string $name): ?string
    {
        $header = $this->getHeaderLine($name);

        return $header !== '' ? $header : null;
    }

    /**
     * @inheritDoc
     */
    public function successful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * @inheritDoc
     */
    public function failed(): bool
    {
        return ! $this->successful();
    }

    /**
     * @inheritDoc
     */
    public function clientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    /**
     * @inheritDoc
     */
    public function serverError(): bool
    {
        return $this->statusCode >= 500;
    }

    /**
     * @inheritDoc
     */
    public function getHttpVersion(): ?string
    {
        return $this->negotiatedHttpVersion;
    }

    /**
     * @inheritDoc
     */
    public function getHttpVersionString(): string
    {
        $version = $this->negotiatedHttpVersion ?? $this->protocol;

        return 'HTTP/' . $version;
    }

    /**
     * Extract cookies from Set-Cookie response headers.
     *
     * @return Cookie[]
     */
    public function getCookies(): array
    {
        $cookies = [];
        foreach ($this->getHeader('Set-Cookie') as $header) {
            $cookie = Cookie::fromSetCookieHeader($header);
            if ($cookie !== null) {
                $cookies[] = $cookie;
            }
        }

        return $cookies;
    }

    /**
     * Apply all cookies from this response into the given jar.
     */
    public function applyCookiesToJar(CookieJarInterface $cookieJar): void
    {
        foreach ($this->getCookies() as $cookie) {
            $cookieJar->setCookie($cookie);
        }
    }

    /**
     * Record the HTTP version that was negotiated for this response.
     *
     * Normalises the raw version string to a canonical form:
     *   '1.0', '1.1'  → kept as-is
     *   '2', '2.0'    → '2'
     *   '3', '3.0'    → '3'
     *
     * @internal Called by the transport layer after the request completes.
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
     * Resolve a dot-notation key against a nested array.
     *
     * A direct key match takes priority over dot-notation traversal,
     * so keys containing literal dots are still reachable.
     *
     * @param array<mixed> $array
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
     * Normalise a raw HTTP version string to canonical form.
     *
     *   '2' or '2.0' → '2'
     *   '3' or '3.0' → '3'
     *   anything else → returned unchanged
     */
    private function normalizeHttpVersion(string $version): string
    {
        return match ($version) {
            '2', '2.0' => '2',
            '3', '3.0' => '3',
            default    => $version,
        };
    }
}