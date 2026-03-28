<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

use Hibla\HttpClient\Builders\CurlOptionsBuilder;
use Hibla\HttpClient\Handlers\HttpHandler;
use Hibla\HttpClient\Handlers\InterceptorHandler;
use Hibla\HttpClient\Interfaces\Cookie\CookieJarInterface;
use Hibla\HttpClient\Interfaces\Handler\TransportOptionsBuilderInterface;
use Hibla\HttpClient\Interfaces\HttpClientInterface;
use Hibla\HttpClient\Interfaces\PendingRequestInterface;
use Hibla\HttpClient\Interfaces\Response\EnhancedResponseInterface;
use Hibla\HttpClient\SSE\SSEBuilder;
use Hibla\HttpClient\Traits\StreamTrait;
use Hibla\Promise\Interfaces\PromiseInterface;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

/**
 * Fluent, immutable, asynchronous HTTP client.
 *
 * Each builder method returns a cloned instance so chains can branch
 * freely without side effects. Terminal methods (get, post, send, stream,
 * download, sse) dispatch the configured request and return a promise.
 *
 * The interceptor pipeline operates on PendingRequestInterface — a narrow
 * contract covering headers, auth, body, cookies, URI and method — so
 * interceptors cannot reach transport-level config (timeout, proxy, retry).
 */
class HttpClient extends Message implements HttpClientInterface
{
    use StreamTrait;

    /**
     * @var array{0: string, 1: string, 2: string}|null
     */
    private ?array $auth = null;

    /**
     * @var array<int|string, mixed>
     */
    private array $options = [];

    /**
     * @var array<string, mixed>
     */
    private array $urlParameters = [];

    /**
     * @var array<int, callable(PendingRequestInterface, callable): PromiseInterface<Response>>
     */
    private array $interceptors = [];

    /**
     * @var TransportOptionsBuilderInterface<array<int|string, mixed>>|null
     */
    private ?TransportOptionsBuilderInterface $transportOptionsBuilder = null;

    private int $timeout = 30;

    private bool $timeoutExplicitlySet = false;

    private string $method = 'GET';

    private int $connectTimeout = 10;

    private bool $followRedirects = true;

    private int $maxRedirects = 5;

    private bool $verifySSL = true;

    private ?string $userAgent = null;

    private ?string $requestTarget = null;

    private UriInterface $uri;

    private HttpHandler $handler;

    private InterceptorHandler $interceptorHandler;

    private ?RetryConfig $retryConfig = null;

    private ?CookieJarInterface $cookieJar = null;

    private ?ProxyConfig $proxyConfig = null;

    /**
     * @param  string|UriInterface             $uri
     * @param  array<string, string|string[]>  $headers
     * @param  mixed|null                      $body
     */
    public function __construct(
        string $method = 'GET',
        string|UriInterface $uri = '',
        array $headers = [],
        mixed $body = null,
        string $version = '2.0',
        ?HttpHandler $handler = null,
    ) {
        $this->method = strtoupper($method);
        $this->uri = $uri instanceof UriInterface ? $uri : new Uri($uri);
        $this->protocol = $version;
        $this->userAgent = GlobalConfig::getUserAgent();
        $this->handler = $handler ?? new HttpHandler();
        $this->interceptorHandler = new InterceptorHandler();

        $this->setHeaders($headers);

        if ($body !== '' && $body !== null) {
            $this->body = $body instanceof StreamInterface ? $body : $this->createTempStream();
            if (! ($body instanceof StreamInterface)) {
                $this->body->write($this->convertToString($body));
                $this->body->rewind();
            }
        } else {
            $this->body = $this->createTempStream();
        }
    }

    /**
     * Return a new instance using the given handler.
     *
     * Primary use case is the Http facade injecting the singleton handler
     * or the testing handler during test runs.
     */
    public function setHandler(HttpHandler $handler): static
    {
        $new = clone $this;
        $new->handler = $handler;

        return $new;
    }

    /**
     * Return a new instance using the given transport options builder.
     *
     * Allows swapping the default cURL builder for a custom implementation
     * (e.g. stream contexts, and non-cURL transports).
     *
     * @param  TransportOptionsBuilderInterface<array<int|string, mixed>>  $builder
     */
    public function setTransportOptionsBuilder(TransportOptionsBuilderInterface $builder): static
    {
        $new = clone $this;
        $new->transportOptionsBuilder = $builder;

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
        $new = clone $this;
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
        $new = $new->withoutHeader('Content-Type');

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function timeout(int $seconds): static
    {
        $new = clone $this;
        $new->timeout = $seconds;
        $new->timeoutExplicitlySet = true;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function connectTimeout(int $seconds): static
    {
        $new = clone $this;
        $new->connectTimeout = $seconds;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function redirects(bool $follow = true, int $max = 5): static
    {
        $new = clone $this;
        $new->followRedirects = $follow;
        $new->maxRedirects = $max;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function verifySSL(bool $verify = true): static
    {
        $new = clone $this;
        $new->verifySSL = $verify;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function httpVersion(string $version): static
    {
        return $this->withProtocolVersion($version);
    }

    /**
     * @inheritDoc
     */
    public function http1(): static
    {
        return $this->withProtocolVersion('1.1');
    }

    /**
     * @inheritDoc
     */
    public function http2(): static
    {
        return $this->withProtocolVersion('2.0');
    }

    /**
     * @inheritDoc
     */
    public function http3(): static
    {
        return $this->withProtocolVersion('3.0');
    }

    /**
     * @inheritDoc
     */
    public function retry(int $maxRetries = 3, float $baseDelay = 1.0, float $backoffMultiplier = 2.0): static
    {
        $new = clone $this;
        $new->retryConfig = new RetryConfig(
            maxRetries:        $maxRetries,
            baseDelay:         $baseDelay,
            backoffMultiplier: $backoffMultiplier,
        );

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function retryWith(RetryConfig $config): static
    {
        $new = clone $this;
        $new->retryConfig = $config;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function noRetry(): static
    {
        $new = clone $this;
        $new->retryConfig = null;

        return $new;
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
            name:     $name,
            value:    $value,
            expires:  isset($attributes['expires']) && is_numeric($attributes['expires'])
                          ? (int) $attributes['expires'] : null,
            domain:   isset($attributes['domain']) && \is_string($attributes['domain'])
                          ? $attributes['domain'] : null,
            path:     isset($attributes['path']) && \is_string($attributes['path'])
                          ? $attributes['path'] : null,
            secure:   isset($attributes['secure']) && (bool) $attributes['secure'],
            httpOnly: isset($attributes['httpOnly']) && (bool) $attributes['httpOnly'],
            maxAge:   isset($attributes['maxAge']) && \is_numeric($attributes['maxAge'])
                          ? (int) $attributes['maxAge'] : null,
            sameSite: isset($attributes['sameSite']) && \is_string($attributes['sameSite'])
                          ? $attributes['sameSite'] : null,
        ));

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function withProxy(string $host, int $port, ?string $username = null, ?string $password = null): static
    {
        $new = clone $this;
        $new->proxyConfig = ProxyConfig::http($host, $port, $username, $password);

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function withSocks4Proxy(string $host, int $port, ?string $username = null): static
    {
        $new = clone $this;
        $new->proxyConfig = ProxyConfig::socks4($host, $port, $username);

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function withSocks5Proxy(string $host, int $port, ?string $username = null, ?string $password = null): static
    {
        $new = clone $this;
        $new->proxyConfig = ProxyConfig::socks5($host, $port, $username, $password);

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function proxyWith(ProxyConfig $config): static
    {
        $new = clone $this;
        $new->proxyConfig = $config;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function noProxy(): static
    {
        $new = clone $this;
        $new->proxyConfig = null;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function withCurlOption(int $option, mixed $value): static
    {
        $this->ensureCurlExtensionLoaded();

        $new = clone $this;
        $new->options[$option] = $value;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function withCurlOptions(array $options): static
    {
        $this->ensureCurlExtensionLoaded();

        $new = clone $this;
        foreach ($options as $option => $value) {
            if (\is_int($option)) {
                $new->options[$option] = $value;
            }
        }

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function withUrlParameter(string $key, mixed $value): static
    {
        $new = clone $this;
        $new->urlParameters[$key] = $value;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function withUrlParameters(array $parameters): static
    {
        $new = clone $this;
        $new->urlParameters = array_merge($new->urlParameters, $parameters);

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function withFile(string $name, mixed $file, ?string $filename = null, ?string $contentType = null): static
    {
        $new = clone $this;

        if (! isset($new->options['multipart'])) {
            $new->options['multipart'] = [];
        }

        $multipart = is_array($new->options['multipart']) ? $new->options['multipart'] : [];

        if ($file instanceof UploadedFileInterface) {
            $multipart[$name] = [
                'name' => $name,
                'contents' => $file->getStream(),
                'filename' => $filename ?? $file->getClientFilename(),
                'Content-Type' => $contentType ?? $file->getClientMediaType(),
            ];
        } elseif (is_string($file) && file_exists($file)) {
            $resource = fopen($file, 'r');
            if ($resource === false) {
                throw new InvalidArgumentException("Unable to open file: {$file}");
            }
            $mime = mime_content_type($file);
            $multipart[$name] = [
                'name' => $name,
                'contents' => $resource,
                'filename' => $filename ?? basename($file),
                'Content-Type' => $contentType ?? ($mime !== false ? $mime : 'application/octet-stream'),
            ];
        } elseif (is_resource($file)) {
            $multipart[$name] = [
                'name' => $name,
                'contents' => $file,
                'filename' => $filename ?? 'file',
                'Content-Type' => $contentType ?? 'application/octet-stream',
            ];
        } else {
            throw new InvalidArgumentException('File must be a file path, UploadedFileInterface, or resource.');
        }

        $new->options['multipart'] = $multipart;

        return $new->withoutHeader('Content-Type');
    }

    /**
     * @inheritDoc
     */
    public function withFiles(array $files): static
    {
        $new = $this;
        foreach ($files as $name => $file) {
            if (\is_array($file)) {
                if (! isset($file['path']) || ! \is_string($file['path'])) {
                    throw new InvalidArgumentException(
                        "File array for '{$name}' must contain a string 'path' key."
                    );
                }
                $new = $new->withFile(
                    $name,
                    $file['path'],
                    isset($file['name']) && \is_string($file['name']) ? $file['name'] : null,
                    isset($file['type']) && \is_string($file['type']) ? $file['type'] : null,
                );
            } else {
                $new = $new->withFile($name, $file);
            }
        }

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function multipartWithFiles(array $data = [], array $files = []): static
    {
        return $this->withMultipart($data)->withFiles($files);
    }

    /**
     * @inheritDoc
     */
    public function interceptRequest(callable $callback): static
    {
        return $this->intercept(
            static function (PendingRequestInterface $request, callable $next) use ($callback): PromiseInterface {
                $result = $callback($request);

                if ($result instanceof PromiseInterface) {
                    return $result->then(
                        static fn (mixed $resolved): PromiseInterface => $next(self::resolvePendingRequest($resolved, true))
                    );
                }

                return $next(self::resolvePendingRequest($result, false));
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function interceptResponse(callable $callback): static
    {
        return $this->intercept(
            static function (PendingRequestInterface $request, callable $next) use ($callback): PromiseInterface {
                return $next($request)->then(
                    static function (Response $response) use ($callback): mixed {
                        $result = $callback($response);

                        if ($result instanceof PromiseInterface) {
                            return $result->then(
                                static fn (mixed $resolved): EnhancedResponseInterface => self::resolveResponse($resolved, true)
                            );
                        }

                        return self::resolveResponse($result, false);
                    }
                );
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function intercept(callable $middleware): static
    {
        $new = clone $this;
        $new->interceptors[] = $middleware;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function get(string $url, array $query = []): PromiseInterface
    {
        if (count($query) > 0) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        return $this->send('GET', $url);
    }

    /**
     * @inheritDoc
     */
    public function post(string $url, array $data = []): PromiseInterface
    {
        $new = $this;
        if (count($data) > 0 && $this->body->getSize() === 0 && ! isset($this->options['multipart'])) {
            $new = $new->withJson($data);
        }

        return $new->send('POST', $url);
    }

    /**
     * @inheritDoc
     */
    public function put(string $url, array $data = []): PromiseInterface
    {
        $new = $this;
        if (count($data) > 0 && $this->body->getSize() === 0 && ! isset($this->options['multipart'])) {
            $new = $new->withJson($data);
        }

        return $new->send('PUT', $url);
    }

    /**
     * @inheritDoc
     */
    public function delete(string $url): PromiseInterface
    {
        return $this->send('DELETE', $url);
    }

    /**
     * @inheritDoc
     */
    public function patch(string $url, array $data = []): PromiseInterface
    {
        $new = $this;
        if (\count($data) > 0 && $this->body->getSize() === 0 && ! isset($this->options['multipart'])) {
            $new = $new->withJson($data);
        }

        return $new->send('PATCH', $url);
    }

    /**
     * @inheritDoc
     */
    public function options(string $url): PromiseInterface
    {
        return $this->send('OPTIONS', $url);
    }

    /**
     * @inheritDoc
     */
    public function head(string $url): PromiseInterface
    {
        return $this->send('HEAD', $url);
    }

    /**
     * @inheritDoc
     */
    public function send(string $method, string $url): PromiseInterface
    {
        $expandedUrl = $this->expandUriTemplate($url);
        $initialRequest = $this->withMethod($method)->withUri(new Uri($expandedUrl));

        return $this->interceptorHandler->process(
            $initialRequest,
            $this->interceptors,
             $this->executeRequest(...),
        );
    }

    // public function fetch(string $url, array $options = []): PromiseInterface
    // {
    //     return $this->send('GET', $url, $options);
    // }

    /**
     * @inheritDoc
     */
    public function stream(string $url, ?callable $onChunk = null): PromiseInterface
    {
        $options = $this->resolveTransportOptionsBuilder()
                        ->buildForStreaming($this->buildClientOptions('GET', $url))
        ;

        return $this->handler->stream($url, $options, $onChunk);
    }

    /**
     * @inheritDoc
     */
    public function streamPost(string $url, mixed $body = null, ?callable $onChunk = null): PromiseInterface
    {
        $postBody = $this->body;

        if ($body !== null) {
            $postBody = $this->createTempStream();
            $postBody->write($this->convertToString($body));
            $postBody->rewind();
        }

        $options = $this->resolveTransportOptionsBuilder()
                        ->buildForStreaming($this->buildClientOptions('POST', $url, $postBody))
        ;

        return $this->handler->stream($url, $options, $onChunk);
    }

    /**
     * @inheritDoc
     */
    public function download(string $url, string $destination): PromiseInterface
    {
        $options = $this->resolveTransportOptionsBuilder()
                        ->buildForDownload($this->buildClientOptions('GET', $url), $destination)
        ;

        return $this->handler->download($url, $destination, $options);
    }

    /**
     * @inheritDoc
     */
    public function sse(string $url): SSEBuilder
    {
        $effectiveTimeout = $this->timeoutExplicitlySet ? $this->timeout : 0;
        $clientOptions = $this->buildClientOptions(
            $this->body->getSize() > 0 ? 'POST' : 'GET',
            $url,
            timeout: $effectiveTimeout,
        );

        $curlOptions = $this->resolveTransportOptionsBuilder()->buildForSSE($clientOptions);

        return new SSEBuilder($url, $this->handler, $curlOptions);
    }

    /**
     * @inheritDoc
     */
    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }

        $target = $this->uri->getPath() ?: '/';

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
     * Execute the request after the interceptor pipeline has settled.
     *
     * Request content (method, URI, headers, body) is read from the
     * processed PendingRequestInterface — interceptors may have modified
     * any of these. Transport config (timeout, proxy, retry, curl options)
     * is always taken from $this, captured via closure at send() time,
     * because the pipeline has no access to those concerns.
     *
     * @return PromiseInterface<Response>
     */
    private function executeRequest(PendingRequestInterface $processed): PromiseInterface
    {
        $clientOptions = new ClientOptions(
            method:            $processed->getMethod(),
            url:               (string) $processed->getUri(),
            headers:           $processed->getHeaders(),
            body:              $processed->getBody(),
            timeout:           $this->timeout,
            connectTimeout:    $this->connectTimeout,
            followRedirects:   $this->followRedirects,
            maxRedirects:      $this->maxRedirects,
            verifySSL:         $this->verifySSL,
            userAgent:         $this->userAgent,
            protocol:          $this->protocol,
            cookieJar:         $this->cookieJar,
            proxyConfig:       $this->proxyConfig,
            auth:              $this->auth,
            additionalOptions: $this->options,
            retryConfig:       $this->retryConfig,
        );

        $transportOptions = $this->resolveTransportOptionsBuilder()->build($clientOptions);

        return $this->handler->sendRequest(
            (string) $processed->getUri(),
            $transportOptions,
            $this->retryConfig,
        );
    }

    /**
     * Build a ClientOptions VO from current builder state.
     *
     * Used by streaming/download/sse terminal methods where the full
     * interceptor pipeline does not run.
     *
     * @param  StreamInterface|null  $bodyOverride  Replaces $this->body when provided.
     */
    private function buildClientOptions(
        string $method,
        string $url,
        ?StreamInterface $bodyOverride = null,
        ?int $timeout = null,
    ): ClientOptions {
        return new ClientOptions(
            method:            $method,
            url:               $url,
            headers:           $this->headers,
            body:              $bodyOverride ?? $this->body,
            timeout:           $timeout ?? $this->timeout,
            connectTimeout:    $this->connectTimeout,
            followRedirects:   $this->followRedirects,
            maxRedirects:      $this->maxRedirects,
            verifySSL:         $this->verifySSL,
            userAgent:         $this->userAgent,
            protocol:          $this->protocol,
            cookieJar:         $this->cookieJar,
            proxyConfig:       $this->proxyConfig,
            auth:              $this->auth,
            additionalOptions: $this->options,
            retryConfig:       $this->retryConfig,
        );
    }

    /**
     * Resolve the transport options builder, defaulting to cURL.
     *
     * @return TransportOptionsBuilderInterface<array<int|string, mixed>>
     */
    private function resolveTransportOptionsBuilder(): TransportOptionsBuilderInterface
    {
        return $this->transportOptionsBuilder ?? new CurlOptionsBuilder();
    }

    /**
     * Assert that an interceptRequest callback returned a PendingRequestInterface.
     *
     * @throws \LogicException
     */
    private static function resolvePendingRequest(mixed $value, bool $fromPromise): PendingRequestInterface
    {
        if ($value === null) {
            throw new \LogicException(sprintf(
                '%s passed to interceptRequest() must %s a %s instance, got null/void.',
                $fromPromise ? 'The ' . PromiseInterface::class : 'Callback',
                $fromPromise ? 'resolve to' : 'return',
                PendingRequestInterface::class,
            ));
        }

        if (! $value instanceof PendingRequestInterface) {
            throw new \LogicException(\sprintf(
                '%s passed to interceptRequest() must %s a %s instance, got %s.',
                $fromPromise ? 'The ' . PromiseInterface::class : 'Callback',
                $fromPromise ? 'resolve to' : 'return',
                PendingRequestInterface::class,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * Assert that an interceptResponse callback returned an EnhancedResponseInterface.
     *
     * @throws \LogicException
     */
    private static function resolveResponse(mixed $value, bool $fromPromise): EnhancedResponseInterface
    {
        if ($value === null) {
            throw new \LogicException(\sprintf(
                '%s passed to interceptResponse() must %s a %s instance, got null/void.',
                $fromPromise ? 'The ' . PromiseInterface::class : 'Callback',
                $fromPromise ? 'resolve to' : 'return',
                EnhancedResponseInterface::class,
            ));
        }

        if (! $value instanceof EnhancedResponseInterface) {
            throw new \LogicException(\sprintf(
                '%s passed to interceptResponse() must %s a %s instance, got %s.',
                $fromPromise ? 'The ' . PromiseInterface::class : 'Callback',
                $fromPromise ? 'resolve to' : 'return',
                EnhancedResponseInterface::class,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * Expand URI template placeholders using configured URL parameters.
     *
     * Supports simple {param} (percent-encoded) and reserved {+param}
     * (special characters preserved).
     */
    private function expandUriTemplate(string $template): string
    {
        if ($this->urlParameters === []) {
            return $template;
        }

        $result = preg_replace_callback(
            '/\{([+]?)([a-zA-Z0-9_]+)\}/',
            function (array $matches): string {
                $reserved = $matches[1] === '+';
                $key = $matches[2];

                if (! isset($this->urlParameters[$key])) {
                    return $matches[0];
                }

                $param = $this->urlParameters[$key];

                if (! is_scalar($param) && ! ($param instanceof \Stringable)) {
                    return $matches[0];
                }

                $value = (string) $param;

                return $reserved ? $value : rawurlencode($value);
            },
            $template,
        );

        return $result ?? $template;
    }

    /**
     * Strip a duplicate token type prefix if the caller already included it.
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
     * @throws \RuntimeException When the cURL extension is not loaded.
     */
    private function ensureCurlExtensionLoaded(): void
    {
        if (! extension_loaded('curl')) {
            throw new \RuntimeException(
                'The cURL extension is not loaded. Please install and enable ext-curl.'
            );
        }
    }
}