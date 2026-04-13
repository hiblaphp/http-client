<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

use Hibla\HttpClient\Interfaces\Cookie\CookieJarInterface;
use Hibla\HttpClient\Interfaces\RequestInterface;
use Hibla\HttpClient\Traits\StreamTrait;
use Hibla\HttpClient\Validators\HeaderValidator;
use Hibla\HttpClient\ValueObjects\Cookie;
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
 * Header names and values are validated against RFC 9110 (HTTP Semantics) and
 * RFC 9112 (HTTP/1.1) via {@see HeaderValidator}. HTTP method tokens are
 * likewise validated against the RFC 9110 §9.1 token grammar.
 *
 * @see RequestInterface The narrow contract exposed to interceptors.
 * @see HttpClient       Owns transport config and dispatches requests.
 * @see HeaderValidator  Centralised RFC 9110/9112 header and method validation.
 */
class Request extends Message implements RequestInterface
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
     *
     * Defaults to GET. Any method set via {@see withMethod()} is validated
     * as an RFC 9110 §9.1 token and stored in upper-case.
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
     * Tracks whether the developer explicitly set a body for this request.
     */
    private bool $bodyExplicitlySet = false;

    /**
     * Initialise a request, optionally seeding all PSR-7 fields up front.
     *
     * All arguments are optional so that the zero-argument form used by
     * HttpClient's fluent API continues to work unchanged. When arguments
     * are supplied every value is routed through the same validated setter
     * that the builder methods use, so construction can never produce an
     * instance that would be rejected mid-chain.
     *
     * Prefer {@see self::create()} when constructing requests inline — the
     * named-constructor form avoids positional-argument awkwardness when only
     * a subset of fields need to be seeded.
     *
     * @param string $method HTTP method token (case-insensitive, stored upper-case).
     * @param string|UriInterface $uri Request URI or a raw URL string.
     * @param array<string, string|string[]> $headers Header map applied via {@see withHeaders()}.
     * @param string|StreamInterface|null $body Raw body string or an existing stream.
     * @param string $version HTTP protocol version (e.g. "1.1", "2").
     *
     * @throws InvalidArgumentException If the method token, any header name/value, or protocol
     *                                  version fails RFC 9110 / 9112 validation.
     */
    public function __construct(
        string $method = 'GET',
        string|UriInterface $uri = '',
        array $headers = [],
        string|StreamInterface|null $body = null,
        string $version = '2.0',
    ) {
        $this->uri = new Uri('');
        $this->body = $this->createTempStream();

        if ($method !== 'GET') {
            $this->applyFrom($this->withMethod($method));
        }

        if ($uri !== '') {
            $this->applyFrom(
                $this->withUri($uri instanceof UriInterface ? $uri : new Uri($uri)),
            );
        }

        if ($headers !== []) {
            $this->applyFrom($this->withHeaders($headers));
        }

        if ($body !== null) {
            $this->applyFrom(
                $body instanceof StreamInterface
                    ? $this->withBody($body)
                    : $this->body($body),
            );
        }

        if ($version !== '2.0') {
            $this->applyFrom($this->withProtocolVersion($version));
        }
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
        $new->bodyExplicitlySet = true;

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
     * Return a clone with the given HTTP method.
     *
     * The method is upper-cased before validation so that callers may pass
     * lower- or mixed-case strings (e.g. "get", "Post") without error. The
     * stored value is always the canonical upper-case form.
     *
     * Validation follows RFC 9110 §9.1:
     *   method = token
     *   token  = 1*tchar
     *
     * Any string that is not a valid token — including empty strings, strings
     * with spaces, or strings containing CR/LF — will throw.
     *
     * @throws InvalidArgumentException If the method is not a valid RFC 9110 token.
     *
     * @inheritDoc
     */
    public function withMethod(string $method): static
    {
        $method = strtoupper($method);

        HeaderValidator::assertValidMethod($method);

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
    public function asXml(): static
    {
        return $this->contentType('application/xml');
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
     * Return a clone with a Bearer (or custom-scheme) Authorization header.
     *
     * The token type is validated as an RFC 9110 token before being placed
     * into the header value, preventing a malformed type string from
     * producing an invalid Authorization header.
     *
     * If the caller already prefixed the token string with the type
     * (e.g. passing "Bearer abc" to withToken("abc", "Bearer")), the
     * duplicate prefix is stripped before the header is set.
     *
     * @throws InvalidArgumentException If $type is not a valid RFC 9110 token.
     *
     * @inheritDoc
     */
    public function withToken(string $token, string $type = 'Bearer'): static
    {
        // Validate the token type as an RFC 9110 token — it ends up inline
        // in the Authorization header value and must not contain spaces,
        // control characters, or other non-tchar bytes.
        HeaderValidator::assertValidMethod($type);

        $token = $this->normalizeToken($token, $type);

        $new = clone $this;
        $new->auth = null;

        return $new->withHeader('Authorization', "{$type} {$token}");
    }

    /**
     * @inheritDoc
     */
    public function withBasicAuth(string $username, string $password): static
    {
        $new = clone $this;
        $new->auth = ['basic', $username, $password];

        return $new->withoutHeader('Authorization');
    }

    /**
     * @inheritDoc
     */
    public function withDigestAuth(string $username, string $password): static
    {
        $new = clone $this;
        $new->auth = ['digest', $username, $password];

        return $new->withoutHeader('Authorization');
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
    public function withXml(string|\SimpleXMLElement $xml): static
    {
        if ($xml instanceof \SimpleXMLElement) {
            $result = $xml->asXML();
            if ($result === false) {
                throw new InvalidArgumentException('Failed to convert SimpleXMLElement to XML string.');
            }
            $xml = $result;
        }

        return $this->body($xml)->contentType('application/xml');
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
        $new->bodyExplicitlySet = true;

        if (isset($new->options['multipart']) && \is_array($new->options['multipart'])) {
            $new->options['multipart'] = array_merge($new->options['multipart'], $data);
        } else {
            $new->options['multipart'] = $data;
        }

        return $new->withoutHeader('Content-Type');
    }

    /**
     * Return a clone with a single cookie appended to the Cookie header.
     *
     * The name must be a valid RFC 9110 token (no separators or control chars).
     * The value must conform to the cookie-octet character set defined in
     * RFC 6265 §4.1.1, enforced here as a client-side policy to prevent header
     * corruption from characters such as ';' which would break attribute parsing.
     *
     * For values containing characters outside the allowed set (e.g. spaces or
     * commas), encode first using Base64 as recommended by RFC 6265 §4.1.1:
     *   withCookie('data', base64_encode($arbitraryValue))
     *
     * @throws InvalidArgumentException If the name or value would produce a
     *                                  malformed Cookie header.
     *
     * @inheritDoc
     */
    public function withCookie(string $name, string $value): static
    {
        Cookie::assertValidName($name);
        Cookie::assertValidValue($value);

        $existing = $this->getHeaderLine('Cookie');
        $newCookie = $name . '=' . $value;

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

        return $new->withoutHeader('Cookie');
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
     * Check if the user has explicitly defined a body for this request.
     *
     * @internal Used by HttpClient.
     */
    public function hasExplicitBody(): bool
    {
        return $this->bodyExplicitlySet;
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
     * @param array<string, mixed> $entry
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
     *
     * Called automatically by {@see withUri()} unless the caller has opted to
     * preserve the existing Host header via the $preserveHost flag.
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
     * Strip a duplicate token-type prefix if the caller already included it.
     *
     * Prevents double-prefixing when a token like "Bearer abc" is passed to
     * {@see withToken()} with type "Bearer", producing "Bearer Bearer abc".
     * The comparison is case-insensitive to handle mixed-case type strings.
     */
    private function normalizeToken(string $token, string $type): string
    {
        $token = trim($token);

        if (stripos($token, $type . ' ') === 0) {
            return trim(substr($token, strlen($type) + 1));
        }

        return $token;
    }

    /**
     * Absorb all mutable state from a clone produced by a builder method.
     *
     * Builder methods (withMethod, withUri, …) return clones rather than
     * mutating $this, which is correct for immutable value objects at runtime
     * but inconvenient during construction — PHP does not allow reassigning
     * $this. This method bridges that gap by copying every property from the
     * post-builder clone back into the instance under construction.
     *
     * Safe to call only from {@see __construct()} before the instance has
     * escaped to user code. Calling it on a live object would silently break
     * immutability guarantees.
     */
    private function applyFrom(self $source): void
    {
        $this->protocol = $source->protocol;
        $this->headers = $source->headers;
        $this->headerNames = $source->headerNames;
        $this->body = $source->body;
        $this->method = $source->method;
        $this->uri = $source->uri;
        $this->requestTarget = $source->requestTarget;
        $this->auth = $source->auth;
        $this->options = $source->options;
        $this->userAgent = $source->userAgent;
        $this->bodyExplicitlySet = $source->bodyExplicitlySet;
        $this->cookieJar = $source->cookieJar;
    }
}
