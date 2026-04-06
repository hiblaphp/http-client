<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

use Composer\InstalledVersions;
use Hibla\HttpClient\Builders\CurlOptionsBuilder;
use Hibla\HttpClient\Handlers\HttpHandler;
use Hibla\HttpClient\Handlers\InterceptorHandler;
use Hibla\HttpClient\Interfaces\Cookie\CookieJarInterface;
use Hibla\HttpClient\Interfaces\Handler\TransportOptionsBuilderInterface;
use Hibla\HttpClient\Interfaces\HttpClientInterface;
use Hibla\HttpClient\Interfaces\RequestInterface;
use Hibla\HttpClient\Interfaces\ResponseInterface;
use Hibla\HttpClient\Interfaces\SSE\SSEBuilderInterface;
use Hibla\HttpClient\SSE\SSEBuilder;
use Hibla\HttpClient\SSE\SSEConnector;
use Hibla\HttpClient\Traits\StreamTrait;
use Hibla\HttpClient\ValueObjects\ClientOptions;
use Hibla\HttpClient\ValueObjects\ProxyConfig;
use Hibla\HttpClient\ValueObjects\RetryConfig;
use Hibla\Promise\Interfaces\PromiseInterface;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

/**
 * Fluent, immutable, asynchronous HTTP client.
 *
 * Owns all transport-level configuration — timeouts, redirects, SSL
 * verification, proxy routing, retry policy, HTTP version negotiation,
 * and raw cURL options. Request-level state (method, URI, headers, body,
 * authentication, cookies) is held by the embedded Request value
 * object, which is what actually flows through the interceptor pipeline.
 *
 * Each builder method returns a cloned instance so chains can branch
 * freely without side effects. Terminal methods (get, post, send, stream,
 * download, sse) dispatch the configured request and return a promise.
 *
 * The interceptor pipeline receives a pure Request — a narrow
 * contract covering headers, auth, body, cookies, URI, and method — so
 * interceptors cannot reach or modify transport-level configuration.
 */
class HttpClient implements HttpClientInterface
{
    use StreamTrait;

    /**
     * Carries all request-level state: method, URI, headers, body, auth, cookies.
     *
     * Interceptors receive and can mutate a snapshot of this object.
     * Transport config never travels through the pipeline — it stays
     * on $this, captured as closure context in executeRequest().
     */
    private Request $request;

    /**
     * @var array<int, mixed>
     */
    private array $curlOptions = [];

    /**
     * @var array<string, mixed>
     */
    private array $urlParameters = [];

    /**
     * @var array<int, callable(RequestInterface, callable): PromiseInterface<ResponseInterface>>
     */
    private array $interceptors = [];

    /**
     * @var TransportOptionsBuilderInterface<array<int|string, mixed>>|null
     */
    private ?TransportOptionsBuilderInterface $transportOptionsBuilder = null;

    private int $timeout = 30;

    private bool $timeoutExplicitlySet = false;

    private int $connectTimeout = 10;

    private bool $followRedirects = true;

    private int $maxRedirects = 5;

    private bool $verifySSL = true;

    private bool $methodExplicitlySet = false;

    private string $protocol = '2.0';

    private ?HttpHandler $handler = null;

    private InterceptorHandler $interceptorHandler;

    private ?RetryConfig $retryConfig = null;

    private ?ProxyConfig $proxyConfig = null;

    /**
     * Initialise a blank HTTP client.
     *
     * A fresh Request is seeded with the library's default User-Agent.
     * Override it per-request with withUserAgent().
     *
     * The HttpHandler is NOT instantiated here — it is created lazily on the
     * first call to a terminal method.
     */
    public function __construct()
    {
        $this->request = (new Request())
            ->withUserAgent(self::defaultUserAgent());

        $this->interceptorHandler = new InterceptorHandler();
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
     * (e.g. stream contexts or non-cURL transports).
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
    public function getProtocolVersion(): string
    {
        return $this->request->getProtocolVersion();
    }

    /**
     * Sets the HTTP protocol version on both the transport layer and the
     * underlying Request so the two remain in sync.
     *
     * @inheritDoc
     */
    public function withProtocolVersion(string $version): static
    {
        $new = clone $this;
        $new->protocol = $version;
        $new->request = $this->request->withProtocolVersion($version);

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function getHeaders(): array
    {
        return $this->request->getHeaders();
    }

    /**
     * @inheritDoc
     */
    public function hasHeader(string $name): bool
    {
        return $this->request->hasHeader($name);
    }

    /**
     * @inheritDoc
     */
    public function getHeader(string $name): array
    {
        return $this->request->getHeader($name);
    }

    /**
     * @inheritDoc
     */
    public function getHeaderLine(string $name): string
    {
        return $this->request->getHeaderLine($name);
    }

    /**
     * @inheritDoc
     */
    public function withHeader(string $name, $value): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->withHeader($name, $value));
    }

    /**
     * @inheritDoc
     */
    public function withAddedHeader(string $name, $value): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->withAddedHeader($name, $value));
    }

    /**
     * @inheritDoc
     */
    public function withoutHeader(string $name): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->withoutHeader($name));
    }

    /**
     * @inheritDoc
     */
    public function getBody(): StreamInterface
    {
        return $this->request->getBody();
    }

    /**
     * @inheritDoc
     */
    public function withBody(StreamInterface $body): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->withBody($body));
    }

    /**
     * @inheritDoc
     */
    public function getRequestTarget(): string
    {
        return $this->request->getRequestTarget();
    }

    /**
     * @inheritDoc
     */
    public function withRequestTarget(string $requestTarget): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->withRequestTarget($requestTarget));
    }

    /**
     * @inheritDoc
     */
    public function getMethod(): string
    {
        return $this->request->getMethod();
    }

    /**
     * @inheritDoc
     */
    public function withMethod(string $method): static
    {
        $new = $this->withUpdatedRequest(fn($r) => $r->withMethod($method));
        $new->methodExplicitlySet = true;

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function getUri(): UriInterface
    {
        return $this->request->getUri();
    }

    /**
     * @inheritDoc
     */
    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->withUri($uri, $preserveHost));
    }

    /**
     * @inheritDoc
     */
    public function contentType(string $type): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->contentType($type));
    }

    /**
     * @inheritDoc
     */
    public function accept(string $type): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->accept($type));
    }

    /**
     * @inheritDoc
     */
    public function asJson(): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->asJson());
    }

    /**
     * @inheritDoc
     */
    public function asForm(): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->asForm());
    }

    /**
     * @inheritDoc
     */
    public function withUserAgent(string $userAgent): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->withUserAgent($userAgent));
    }

    /**
     * @inheritDoc
     */
    public function withHeaders(array $headers): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->withHeaders($headers));
    }

    /**
     * @inheritDoc
     */
    public function withToken(string $token, string $type = 'Bearer'): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->withToken($token, $type));
    }

    /**
     * @inheritDoc
     */
    public function withBasicAuth(string $username, string $password): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->withBasicAuth($username, $password));
    }

    /**
     * @inheritDoc
     */
    public function withDigestAuth(string $username, string $password): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->withDigestAuth($username, $password));
    }

    /**
     * @inheritDoc
     */
    public function body(string $content): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->body($content));
    }

    /**
     * @inheritDoc
     */
    public function withJson(array $data): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->withJson($data));
    }

    /**
     * @inheritDoc
     */
    public function asXml(): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->asXml());
    }

    /**
     * @inheritDoc
     */
    public function withXml(string|\SimpleXMLElement $xml): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->withXml($xml));
    }

    /**
     * @inheritDoc
     */
    public function withForm(array $data): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->withForm($data));
    }

    /**
     * @inheritDoc
     */
    public function withMultipart(array $data): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->withMultipart($data));
    }

    /**
     * @inheritDoc
     */
    public function withCookie(string $name, string $value): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->withCookie($name, $value));
    }

    /**
     * @inheritDoc
     */
    public function withCookies(array $cookies): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->withCookies($cookies));
    }

    /**
     * @inheritDoc
     */
    public function withCookieJar(): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->withCookieJar());
    }

    /**
     * @inheritDoc
     */
    public function useCookieJar(CookieJarInterface $cookieJar): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->useCookieJar($cookieJar));
    }

    /**
     * @inheritDoc
     */
    public function clearCookies(): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->clearCookies());
    }

    /**
     * @inheritDoc
     */
    public function getCookieJar(): ?CookieJarInterface
    {
        return $this->request->getCookieJar();
    }

    /**
     * @inheritDoc
     */
    public function cookieWithAttributes(string $name, string $value, array $attributes = []): static
    {
        return $this->withUpdatedRequest(fn($r) => $r->cookieWithAttributes($name, $value, $attributes));
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
            maxRetries: $maxRetries,
            baseDelay: $baseDelay,
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
        $new->curlOptions[$option] = $value;

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
                $new->curlOptions[$option] = $value;
            }
        }

        return $new;
    }

    /**
     * @inheritDoc
     */
    public function withFile(string $name, mixed $file, ?string $filename = null, ?string $contentType = null): static
    {
        if ($file instanceof UploadedFileInterface) {
            $entry = [
                'name' => $name,
                'contents' => $file->getStream(),
                'filename' => $filename ?? $file->getClientFilename(),
                'Content-Type' => $contentType ?? $file->getClientMediaType(),
            ];
        } elseif (\is_string($file) && file_exists($file)) {
            $mime = mime_content_type($file);
            $entry = [
                'name' => $name,
                'filepath' => $file,
                'filename' => $filename ?? basename($file),
                'Content-Type' => $contentType ?? ($mime !== false ? $mime : 'application/octet-stream'),
            ];
        } elseif (\is_resource($file)) {
            $entry = [
                'name' => $name,
                'contents' => $file,
                'filename' => $filename ?? 'file',
                'Content-Type' => $contentType ?? 'application/octet-stream',
            ];
        } else {
            throw new InvalidArgumentException('File must be a file path, UploadedFileInterface, or resource.');
        }

        return $this->withUpdatedRequest(fn($r) => $r->withMultipartEntry($name, $entry));
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
    public function interceptRequest(callable $callback): static
    {
        return $this->intercept(
            static function (RequestInterface $request, callable $next) use ($callback): PromiseInterface {
                /** @var callable(RequestInterface): PromiseInterface<ResponseInterface> $next */
                $result = $callback($request);

                if ($result instanceof PromiseInterface) {
                    /** @var PromiseInterface<RequestInterface> $result */
                    /** @var PromiseInterface<ResponseInterface> $chained */
                    $chained = $result->then(
                        static fn(mixed $resolved): PromiseInterface => $next(self::resolveRequest($resolved, true))
                    );

                    return $chained;
                }

                /** @var PromiseInterface<ResponseInterface> $resolved */
                $resolved = $next(self::resolveRequest($result, false));

                return $resolved;
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function interceptResponse(callable $callback): static
    {
        return $this->intercept(
            static function (RequestInterface $request, callable $next) use ($callback): PromiseInterface {
                /** @var PromiseInterface $nextPromise */
                $nextPromise = $next($request);

                return $nextPromise->then(
                    static function (mixed $response) use ($callback): mixed {
                        if (! $response instanceof ResponseInterface) {
                            return $response;
                        }

                        $result = $callback($response);

                        if ($result instanceof PromiseInterface) {
                            /** @var PromiseInterface<ResponseInterface> $result */
                            return $result->then(
                                static fn(mixed $resolved): ResponseInterface => self::resolveResponse($resolved, true)
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
        if (\count($query) > 0) {
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
        if (
            \count($data) > 0
            && $this->request->getBody()->getSize() === 0
            && ! isset($this->request->getOptions()['multipart'])
        ) {
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
        if (
            \count($data) > 0
            && $this->request->getBody()->getSize() === 0
            && ! isset($this->request->getOptions()['multipart'])
        ) {
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
        if (
            \count($data) > 0
            && $this->request->getBody()->getSize() === 0
            && ! isset($this->request->getOptions()['multipart'])
        ) {
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
        $initialRequest = $this->request
            ->withMethod($method)
            ->withUri(new Uri($expandedUrl));

        return $this->interceptorHandler->process(
            request: $initialRequest,
            interceptors: $this->interceptors,
            executor: $this->executeRequest(...),
        );
    }

    /**
     * @inheritDoc
     */
    public function stream(string $url, ?callable $onChunk = null): PromiseInterface
    {
        $expandedUrl = $this->expandUriTemplate($url);
        $initialRequest = $this->request
            ->withMethod($this->getMethod())
            ->withUri(new Uri($expandedUrl));

        return $this->interceptorHandler->process(
            request: $initialRequest,
            interceptors: $this->interceptors,
            executor: function (RequestInterface $processed) use ($onChunk) {
                $clientOptions = $this->buildClientOptionsFromProcessed($processed);
                $options = $this->resolveTransportOptionsBuilder()->buildForStreaming($clientOptions);

                return $this->resolveHandler()->stream((string) $processed->getUri(), $options, $onChunk);
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function upload(string $url, string $source, ?callable $onProgress = null): PromiseInterface
    {
        $expandedUrl = $this->expandUriTemplate($url);
        $method = $this->methodExplicitlySet ? $this->getMethod() : 'PUT';
        $initialRequest = $this->request
            ->withMethod($method)
            ->withUri(new Uri($expandedUrl));

        return $this->interceptorHandler->process(
            request: $initialRequest,
            interceptors: $this->interceptors,
            executor: function (RequestInterface $processed) use ($source, $onProgress) {
                $effectiveTimeout = $this->timeoutExplicitlySet ? $this->timeout : 0;
                $clientOptions = $this->buildClientOptionsFromProcessed($processed, timeout: $effectiveTimeout);
                $options = $this->resolveTransportOptionsBuilder()->buildForUpload($clientOptions, $source);

                return $this->resolveHandler()->upload(
                    (string) $processed->getUri(),
                    $source,
                    $options,
                    $onProgress,
                );
            },
            requireResponse: false
        );
    }

    /**
     * @inheritDoc
     */
    public function download(string $url, string $destination, ?callable $onProgress = null): PromiseInterface
    {
        $expandedUrl = $this->expandUriTemplate($url);
        $initialRequest = $this->request
            ->withMethod($this->getMethod())
            ->withUri(new Uri($expandedUrl));

        return $this->interceptorHandler->process(
            request: $initialRequest,
            interceptors: $this->interceptors,
            executor: function (RequestInterface $processed) use ($destination, $onProgress) {
                $effectiveTimeout = $this->timeoutExplicitlySet ? $this->timeout : 0;
                $clientOptions = $this->buildClientOptionsFromProcessed($processed, timeout: $effectiveTimeout);
                $options = $this->resolveTransportOptionsBuilder()->buildForDownload($clientOptions, $destination);

                return $this->resolveHandler()->download(
                    (string) $processed->getUri(),
                    $destination,
                    $options,
                    $onProgress,
                );
            },
            requireResponse: false
        );
    }

    /**
     * @inheritDoc
     */
    public function sse(string $url): SSEBuilderInterface
    {
        $expandedUrl = $this->expandUriTemplate($url);

        $method = $this->methodExplicitlySet
            ? $this->getMethod()
            : ($this->request->getBody()->getSize() > 0 ? 'POST' : 'GET');

        $initialRequest = $this->request
            ->withMethod($method)
            ->withUri(new Uri($expandedUrl));

        $effectiveTimeout = $this->timeoutExplicitlySet ? $this->timeout : 0;
        $clientOptions = $this->buildClientOptions(
            $initialRequest->getMethod(),
            $expandedUrl,
            timeout: $effectiveTimeout,
        );

        $curlOptions = $this->resolveTransportOptionsBuilder()->buildForSSE($clientOptions);

        $connector = new SSEConnector(
            interceptorHandler: $this->interceptorHandler,
            httpHandler: $this->resolveHandler(),
            interceptors: $this->interceptors,
            request: $initialRequest
        );

        return new SSEBuilder($expandedUrl, $curlOptions, $connector);
    }

    /**
     * Execute the request after the interceptor pipeline has settled.
     *
     * Request content (method, URI, headers, body, auth, cookies, user-agent)
     * is read from the processed Request — interceptors may have
     * modified any of these. Transport config (timeout, proxy, retry, cURL
     * options) is always taken from $this, captured via closure at send() time,
     * because the pipeline has no access to those concerns.
     *
     * The instanceof guard future-proofs against third-party RequestInterface
     * implementations being passed in; in practice $processed is always a
     * Request produced by send().
     *
     * @return PromiseInterface<ResponseInterface>
     */
    private function executeRequest(RequestInterface $processed): PromiseInterface
    {
        $clientOptions = $this->buildClientOptionsFromProcessed($processed);
        $transportOptions = $this->resolveTransportOptionsBuilder()->build($clientOptions);

        return $this->resolveHandler()->sendRequest(
            (string) $processed->getUri(),
            $transportOptions,
            $this->retryConfig,
        );
    }

    /**
     * Builds ClientOptions from a processed RequestInterface (post-interceptors).
     */
    private function buildClientOptionsFromProcessed(
        RequestInterface $processed,
        ?int $timeout = null,
    ): ClientOptions {
        $auth = $processed instanceof Request ? $processed->getAuth() : null;
        $bodyOptions = $processed instanceof Request ? $processed->getOptions() : [];
        $userAgent = $processed instanceof Request ? $processed->getUserAgent() : null;

        /** @var array<string, array<string>> $headers */
        $headers = $processed->getHeaders();

        return new ClientOptions(
            method: $processed->getMethod(),
            url: (string) $processed->getUri(),
            headers: $headers,
            body: $processed->getBody(),
            timeout: $timeout ?? $this->timeout,
            connectTimeout: $this->connectTimeout,
            followRedirects: $this->followRedirects,
            maxRedirects: $this->maxRedirects,
            verifySSL: $this->verifySSL,
            userAgent: $userAgent,
            protocol: $this->protocol,
            cookieJar: $processed->getCookieJar(),
            proxyConfig: $this->proxyConfig,
            auth: $auth,
            additionalOptions: $bodyOptions + $this->curlOptions,
            retryConfig: $this->retryConfig,
        );
    }

    /**
     * Build a ClientOptions VO from current builder state.
     *
     * Used by streaming, download, and SSE terminal methods where the full
     * interceptor pipeline does not run.
     *
     * @param  StreamInterface|null  $bodyOverride  Replaces the request body when provided.
     */
    private function buildClientOptions(
        string $method,
        string $url,
        ?StreamInterface $bodyOverride = null,
        ?int $timeout = null,
    ): ClientOptions {
        /** @var array<string, array<string>> $headers */
        $headers = $this->request->getHeaders();

        return new ClientOptions(
            method: $method,
            url: $url,
            headers: $headers,
            body: $bodyOverride ?? $this->request->getBody(),
            timeout: $timeout ?? $this->timeout,
            connectTimeout: $this->connectTimeout,
            followRedirects: $this->followRedirects,
            maxRedirects: $this->maxRedirects,
            verifySSL: $this->verifySSL,
            userAgent: $this->request->getUserAgent(),
            protocol: $this->protocol,
            cookieJar: $this->request->getCookieJar(),
            proxyConfig: $this->proxyConfig,
            auth: $this->request->getAuth(),
            additionalOptions: $this->request->getOptions() + $this->curlOptions,
            retryConfig: $this->retryConfig,
        );
    }

    /**
     * Return a clone of this client with the embedded Request replaced
     * by the result of $fn.
     *
     * All delegating builder methods funnel through here to guarantee
     * immutability is preserved on every step of the chain without
     * repeating the clone-and-assign boilerplate in every method.
     *
     * @param  callable(Request): Request  $fn
     */
    private function withUpdatedRequest(callable $fn): static
    {
        $new = clone $this;
        /** @var Request $updated */
        $updated = $fn($this->request);
        $new->request = $updated;

        return $new;
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
     * Resolve the HttpHandler, creating the default instance on first call.
     *
     * Lazy initialization avoids spinning up cURL multi-handle or event-loop
     * resources on every HttpClient construction — only requests that
     * actually dispatch pay the startup cost.
     */
    private function resolveHandler(): HttpHandler
    {
        return $this->handler ??= new HttpHandler();
    }

    /**
     * Expand URI template placeholders using the configured URL parameters.
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
     * Assert that an interceptRequest callback returned a RequestInterface.
     *
     * @throws \LogicException
     */
    private static function resolveRequest(mixed $value, bool $fromPromise): RequestInterface
    {
        if ($value === null) {
            throw new \LogicException(sprintf(
                '%s passed to interceptRequest() must %s a %s instance, got null/void.',
                $fromPromise ? 'The ' . PromiseInterface::class : 'Callback',
                $fromPromise ? 'resolve to' : 'return',
                RequestInterface::class,
            ));
        }

        if (! $value instanceof RequestInterface) {
            throw new \LogicException(\sprintf(
                '%s passed to interceptRequest() must %s a %s instance, got %s.',
                $fromPromise ? 'The ' . PromiseInterface::class : 'Callback',
                $fromPromise ? 'resolve to' : 'return',
                RequestInterface::class,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * Assert that an interceptResponse callback returned an ResponseInterface.
     *
     * @throws \LogicException
     */
    private static function resolveResponse(mixed $value, bool $fromPromise): ResponseInterface
    {
        if ($value === null) {
            throw new \LogicException(\sprintf(
                '%s passed to interceptResponse() must %s a %s instance, got null/void.',
                $fromPromise ? 'The ' . PromiseInterface::class : 'Callback',
                $fromPromise ? 'resolve to' : 'return',
                ResponseInterface::class,
            ));
        }

        if (! $value instanceof ResponseInterface) {
            throw new \LogicException(\sprintf(
                '%s passed to interceptResponse() must %s a %s instance, got %s.',
                $fromPromise ? 'The ' . PromiseInterface::class : 'Callback',
                $fromPromise ? 'resolve to' : 'return',
                ResponseInterface::class,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    private static function defaultUserAgent(): string
    {
        $version = InstalledVersions::getPrettyVersion('hiblaphp/http-client') ?? '1.0';

        return "hibla-http-client/{$version} PHP/" . PHP_VERSION;
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
