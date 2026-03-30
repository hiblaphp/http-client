<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

use Hibla\HttpClient\Interfaces\Cookie\CookieJarInterface;
use Hibla\HttpClient\Interfaces\PendingRequestInterface;
use Hibla\HttpClient\Traits\StreamTrait;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Immutable value object representing an HTTP request as it flows through
 * the interceptor pipeline.
 *
 * Encapsulates all request-level state — method, URI, headers, body,
 * authentication, and cookies. Transport concerns (timeout, proxy, retry,
 * cURL options) are deliberately omitted; those live on HttpClient and
 * remain fixed for the lifetime of any pipeline run.
 *
 * Each builder method returns a cloned instance so chains can branch freely
 * without side effects.
 *
 * @see PendingRequestInterface  The narrow contract exposed to interceptors.
 * @see HttpClient               Owns transport config and dispatches requests.
 */
class PendingRequest extends Message implements PendingRequestInterface
{
    use StreamTrait;

    /**
     * HTTP Basic or Digest auth credentials.
     *
     * Stored as a three-element tuple so the transport layer can perform
     * the appropriate handshake rather than pre-encoding credentials here.
     *
     * @var array{0: string, 1: string, 2: string}|null
     */
    private ?array $auth = null;

    /**
     * Body-level transport options — primarily the multipart field map
     * populated by withMultipart() and the file-attachment methods.
     *
     * @var array<string, mixed>
     */
    private array $options = [];

    /**
     * HTTP method in upper-case (GET, POST, …).
     */
    private string $method = 'GET';

    /**
     * An explicit request-target override, or null to derive it from the URI.
     */
    private ?string $requestTarget = null;

    /**
     * The request URI.
     */
    private UriInterface $uri;

    /**
     * Active cookie jar, or null when cookie-jar management is disabled.
     */
    private ?CookieJarInterface $cookieJar = null;

    /**
     * User-Agent string, or null to fall back to the globally configured agent.
     */
    private ?string $userAgent = null;

    /**
     * Initialise a blank pending request.
     *
     * Prefer the HttpClient fluent API over constructing PendingRequest
     * directly. HttpClient seeds the initial User-Agent from GlobalConfig
     * before handing the instance to the interceptor pipeline.
     */
    public function __construct()
    {
        $this->uri = new Uri('');
        $this->body = $this->createTempStream();
    }

    /**
     * {@inheritdoc}
     */
    public function withProtocolVersion(string $version): static
    {
        /** @var static $new */
        $new = parent::withProtocolVersion($version);

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withHeader(string $name, $value): static
    {
        /** @var static $new */
        $new = parent::withHeader($name, $value);

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withAddedHeader(string $name, $value): static
    {
        /** @var static $new */
        $new = parent::withAddedHeader($name, $value);

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withoutHeader(string $name): static
    {
        /** @var static $new */
        $new = parent::withoutHeader($name);

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withBody(StreamInterface $body): static
    {
        /** @var static $new */
        $new = parent::withBody($body);

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }

        $path = $this->uri->getPath();
        $target = $path !== '' ? $path : '/';

        if ($this->uri->getQuery() !== '') {
            $target .= '?' . $this->uri->getQuery();
        }

        return $target;
    }

    /**
     * @inheritDoc
     */
    public function withRequestTarget(string $requestTarget): static
    {
        if ($this->requestTarget === $requestTarget) {
            return $this;
        }

        $new = clone $this;
        $new->requestTarget = $requestTarget;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * @inheritDoc
     */
    public function withMethod(string $method): static
    {
        $method = strtoupper($method);
        if ($this->method === $method) {
            return $this;
        }

        $new = clone $this;
        $new->method = $method;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    /**
     * @inheritDoc
     */
    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        if ($uri === $this->uri) {
            return $this;
        }

        $new = clone $this;
        $new->uri = $uri;

        if (! $preserveHost || ! isset($this->headerNames['host'])) {
            $new = $new->updateHostFromUri();
        }

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function contentType(string $type): static
    {
        return $this->withHeader('Content-Type', $type);
    }

    /**
     * @inheritDoc
     */
    public function accept(string $type): static
    {
        return $this->withHeader('Accept', $type);
    }

    /**
     * @inheritDoc
     */
    public function asJson(): static
    {
        return $this->contentType('application/json');
    }

    /**
     * @inheritDoc
     */
    public function asForm(): static
    {
        return $this->contentType('application/x-www-form-urlencoded');
    }

    /**
     * @inheritDoc
     */
    public function withUserAgent(string $userAgent): static
    {
        $new = clone $this;
        $new->userAgent = $userAgent;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function withHeaders(array $headers): static
    {
        $new = $this;
        foreach ($headers as $name => $value) {
            $new = $new->withHeader($name, $value);
        }

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function withToken(string $token, string $type = 'Bearer'): static
    {
        $token = $this->normalizeToken($token, $type);

        return $this->withHeader('Authorization', "{$type} {$token}");
    }

    /**
     * @inheritDoc
     */
    public function withBasicAuth(string $username, string $password): static
    {
        $new = clone $this;
        $new->auth = ['basic', $username, $password];

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function withDigestAuth(string $username, string $password): static
    {
        $new = clone $this;
        $new->auth = ['digest', $username, $password];

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function body(string $content): static
    {
        $stream = $this->createTempStream();
        $stream->write($content);
        $stream->rewind();

        return $this->withBody($stream);
    }

    /**
     * @inheritDoc
     */
    public function withJson(array $data): static
    {
        $json = json_encode($data);
        if ($json === false) {
            throw new InvalidArgumentException('Failed to encode data as JSON.');
        }

        return $this->body($json)->contentType('application/json');
    }

    /**
     * @inheritDoc
     */
    public function withForm(array $data): static
    {
        return $this->body(http_build_query($data))
            ->contentType('application/x-www-form-urlencoded')
        ;
    }

    /**
     * @inheritDoc
     */
    public function withMultipart(array $data): static
    {
        $new = clone $this;
        $new->body = $this->createTempStream();
        $new->options['multipart'] = $data;

        return $new->withoutHeader('Content-Type');
    }

    /**
     * @inheritDoc
     */
    public function withCookie(string $name, string $value): static
    {
        $existing = $this->getHeaderLine('Cookie');
        $newCookie = $name . '=' . urlencode($value);

        return $this->withHeader(
            'Cookie',
            $existing !== '' ? $existing . '; ' . $newCookie : $newCookie,
        );
    }

    /**
     * @inheritDoc
     */
    public function withCookies(array $cookies): static
    {
        $new = $this;
        foreach ($cookies as $name => $value) {
            $new = $new->withCookie($name, $value);
        }

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function withCookieJar(): static
    {
        return $this->useCookieJar(new CookieJar());
    }

    /**
     * @inheritDoc
     */
    public function useCookieJar(CookieJarInterface $cookieJar): static
    {
        $new = clone $this;
        $new->cookieJar = $cookieJar;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function clearCookies(): static
    {
        $new = clone $this;
        $new->cookieJar?->clear();

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function getCookieJar(): ?CookieJarInterface
    {
        return $this->cookieJar;
    }

    /**
     * @inheritDoc
     */
    public function cookieWithAttributes(string $name, string $value, array $attributes = []): static
    {
        $new = clone $this;

        if ($new->cookieJar === null) {
            $new->cookieJar = new CookieJar();
        }

        $new->cookieJar->setCookie(new Cookie(
            name: $name,
            value: $value,
            expires: isset($attributes['expires']) && is_numeric($attributes['expires'])
                ? (int) $attributes['expires'] : null,
            domain: isset($attributes['domain']) && \is_string($attributes['domain'])
                ? $attributes['domain'] : null,
            path: isset($attributes['path']) && \is_string($attributes['path'])
                ? $attributes['path'] : null,
            secure: isset($attributes['secure']) && (bool) $attributes['secure'],
            httpOnly: isset($attributes['httpOnly']) && (bool) $attributes['httpOnly'],
            maxAge: isset($attributes['maxAge']) && \is_numeric($attributes['maxAge'])
                ? (int) $attributes['maxAge'] : null,
            sameSite: isset($attributes['sameSite']) && \is_string($attributes['sameSite'])
                ? $attributes['sameSite'] : null,
        ));

        return $new;
    }

    /**
     * Return the configured auth tuple, or null when no auth strategy has been set.
     *
     * @return array{0: string, 1: string, 2: string}|null
     *
     * @internal Used by HttpClient when assembling ClientOptions.
     */
    public function getAuth(): ?array
    {
        return $this->auth;
    }

    /**
     * Return body-level transport options (e.g. the multipart field map).
     *
     * @return array<string, mixed>
     *
     * @internal Used by HttpClient when assembling ClientOptions.
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Return the configured User-Agent string, or null when unset.
     *
     * @internal Used by HttpClient when assembling ClientOptions.
     */
    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    /**
     * Return a clone with a single multipart entry added or replaced.
     *
     * File-attachment logic (resource opening, MIME detection) lives on
     * HttpClient. This method exists so HttpClient can write the resolved
     * entry into the multipart map without duplicating withMultipart()'s
     * bookkeeping, and without exposing raw options to the public API.
     *
     * @param  array<string, mixed>  $entry
     *
     * @internal Called by HttpClient::withFile() after resolving the resource.
     */
    public function withMultipartEntry(string $name, array $entry): static
    {
        $new = clone $this;

        if (! isset($new->options['multipart']) || ! \is_array($new->options['multipart'])) {
            $new->options['multipart'] = [];
        }

        /** @var array<string, mixed> $multipart */
        $multipart = $new->options['multipart'];
        $multipart[$name] = $entry;

        $new->options['multipart'] = $multipart;

        return $new->withoutHeader('Content-Type');
    }

    /**
     * Sync the Host header from the current URI.
     */
    private function updateHostFromUri(): static
    {
        $host = $this->uri->getHost();
        if ($host === '') {
            return $this;
        }

        if (($port = $this->uri->getPort()) !== null) {
            $host .= ':' . $port;
        }

        return $this->withHeader('Host', $host);
    }

    /**
     * Strip a duplicate token type prefix if the caller already included it.
     *
     * Prevents double-prefixing when a token like "Bearer abc" is passed
     * to withToken() with type "Bearer".
     */
    private function normalizeToken(string $token, string $type): string
    {
        $token = trim($token);

        if (stripos($token, $type . ' ') === 0) {
            return trim(substr($token, strlen($type) + 1));
        }

        return $token;
    }
}
