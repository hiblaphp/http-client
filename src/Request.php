<?php

namespace Hibla\HttpClient;

use Hibla\HttpClient\Handlers\HttpHandler;
use Hibla\HttpClient\Handlers\OptionsBuilderHandler;
use Hibla\HttpClient\Handlers\RequestInterceptorHandler;
use Hibla\HttpClient\Handlers\ResponseInterceptorHandler;
use Hibla\HttpClient\Interfaces\CompleteHttpClientInterface;
use Hibla\HttpClient\Interfaces\CookieJarInterface;
use Hibla\HttpClient\SSE\SSEEvent;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\HttpClient\Traits\StreamTrait;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;

/**
 * A fluent, chainable, asynchronous HTTP request builder.
 *
 * This class provides a rich interface for constructing and sending HTTP requests
 * asynchronously. It supports setting headers, body, timeouts, authentication,
 * and retry logic in a clean, readable way.
 *
 * @property-read string|null $sseDataFormat
 * @property-read (callable(SSEEvent): mixed)|null $sseMapper
 */
class Request extends Message implements CompleteHttpClientInterface
{
    use StreamTrait;

    private HttpHandler $handler;
    private OptionsBuilderHandler $optionsBuilder;
    private ?CookieJarInterface $cookieJar = null;
    private string $method = 'GET';
    private ?string $requestTarget = null;
    private UriInterface $uri;
    /** @var array<int|string, mixed> */
    private array $options = [];
    private int $timeout = 30;
    private int $connectTimeout = 10;
    private bool $followRedirects = true;
    private int $maxRedirects = 5;
    private bool $verifySSL = true;
    private ?string $userAgent = null;
    /** @var array{0: string, 1: string, 2: string}|null */
    private ?array $auth = null;
    /** @var array<string, mixed> */
    private array $urlParameters = [];
    private ?RetryConfig $retryConfig = null;
    private ?CacheConfig $cacheConfig = null;
    /** @var array<int, callable(Request): Request> Callbacks to intercept the request before it is sent. */
    private array $requestInterceptors = [];
    /** @var array<int, callable(Response): Response> Callbacks to intercept the response after it is received. */
    private array $responseInterceptors = [];
    private ?ProxyConfig $proxyConfig = null;
    private ?SSEReconnectConfig $sseReconnectConfig = null;
    private ?string $sseDataFormat = null;
    /** @var (callable(mixed): mixed)|null */
    private $sseMapper = null;
    private ?RequestInterceptorHandler $requestInterceptorHandler = null;
    private ?ResponseInterceptorHandler $responseInterceptorHandler = null;

    /**
     * Initializes a new Request builder instance.
     *
     * @param  HttpHandler  $handler  The core handler responsible for dispatching the request.
     * @param  string  $method  The HTTP method for the request.
     * @param  string|UriInterface  $uri  The URI for the request.
     * @param  array<string, string|string[]>  $headers  An associative array of headers.
     * @param  mixed|null  $body  The request body.
     * @param  string  $version  The HTTP protocol version.
     */
    public function __construct(HttpHandler $handler, string $method = 'GET', $uri = '', array $headers = [], $body = null, string $version = '2.0')
    {
        $this->handler = $handler;
        $this->optionsBuilder = new OptionsBuilderHandler();
        $this->method = strtoupper($method);
        $this->uri = $uri instanceof UriInterface ? $uri : new Uri($uri);
        $this->setHeaders($headers);
        $this->protocol = $version;
        $this->userAgent = 'Hibla-HTTP-Client';

        if ($body !== '' && $body !== null) {
            $this->body = $body instanceof Stream ? $body : $this->createTempStream();
            if (! ($body instanceof Stream)) {
                $bodyString = $this->convertToString($body);
                $this->body->write($bodyString);
                $this->body->rewind();
            }
        } else {
            $this->body = $this->createTempStream();
        }
    }

    /**
     * Adds a request interceptor.
     *
     * The callback will receive the Request object before it is sent. It MUST
     * return a Request object, allowing for immutable modifications.
     *
     * @param  callable(Request): Request  $callback
     */
    public function interceptRequest(callable $callback): self
    {
        $new = clone $this;
        $new->requestInterceptors[] = $callback;

        return $new;
    }

    /**
     * Adds a response interceptor.
     *
     * The callback will receive the final Response object. It MUST return a
     * Response object, allowing for inspection or modification.
     *
     * @param  callable(Response): Response  $callback
     */
    public function interceptResponse(callable $callback): self
    {
        $new = clone $this;
        $new->responseInterceptors[] = $callback;

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }

        $target = $this->uri->getPath();
        if ($target === '') {
            $target = '/';
        }
        if ($this->uri->getQuery() !== '') {
            $target .= '?' . $this->uri->getQuery();
        }

        return $target;
    }

    /**
     * {@inheritdoc}
     */
    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        if ($this->requestTarget === $requestTarget) {
            return $this;
        }

        $new = clone $this;
        $new->requestTarget = $requestTarget;

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * {@inheritdoc}
     */
    public function withMethod(string $method): RequestInterface
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
     * {@inheritdoc}
     */
    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    /**
     * {@inheritdoc}
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
     * Set multiple headers at once.
     *
     * @param  array<string, string|string[]>  $headers  An associative array of header names to values.
     * @return self For fluent method chaining.
     */
    public function withHeaders(array $headers): self
    {
        $new = clone $this;
        foreach ($headers as $name => $value) {
            $new = $new->withHeader($name, $value);
        }

        return $new;
    }

    /**
     * Set the Content-Type header.
     *
     * @param  string  $type  The media type (e.g., 'application/json').
     * @return self For fluent method chaining.
     */
    public function contentType(string $type): self
    {
        return $this->withHeader('Content-Type', $type);
    }

    /**
     * Set the Accept header.
     *
     * @param  string  $type  The desired media type (e.g., 'application/json').
     * @return self For fluent method chaining.
     */
    public function accept(string $type): self
    {
        return $this->withHeader('Accept', $type);
    }

    /**
     * Set Content-Type to application/json.
     *
     * @return self For fluent method chaining.
     */
    public function asJson()
    {
        return $this->contentType('application/json');
    }

    /**
     * Set Content-Type to application/x-www-form-urlencoded.
     *
     * @return self For fluent method chaining.
     */
    public function asForm()
    {
        return $this->contentType('application/x-www-form-urlencoded');
    }

    /**
     * Attach a bearer token to the Authorization header.
     *
     * @param  string  $token  The bearer token.
     * @return self For fluent method chaining.
     */
    public function withToken(string $token): self
    {
        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /**
     * Set basic authentication credentials.
     *
     * @param  string  $username  The username.
     * @param  string  $password  The password.
     * @return self For fluent method chaining.
     */
    public function withBasicAuth(string $username, string $password): self
    {
        $new = clone $this;
        $new->auth = ['basic', $username, $password];

        return $new;
    }

    /**
     * Set digest authentication credentials.
     *
     * @param  string  $username  The username.
     * @param  string  $password  The password.
     * @return self For fluent method chaining.
     */
    public function withDigestAuth(string $username, string $password): self
    {
        $new = clone $this;
        $new->auth = ['digest', $username, $password];

        return $new;
    }

    /**
     * Set the total request timeout in seconds.
     *
     * @param  int  $seconds  The timeout duration.
     * @return self For fluent method chaining.
     */
    public function timeout(int $seconds): self
    {
        $new = clone $this;
        $new->timeout = $seconds;

        return $new;
    }

    /**
     * Set the connection timeout in seconds.
     *
     * @param  int  $seconds  The timeout duration for the connection phase.
     * @return self For fluent method chaining.
     */
    public function connectTimeout(int $seconds): self
    {
        $new = clone $this;
        $new->connectTimeout = $seconds;

        return $new;
    }

    /**
     * Configure automatic redirect following.
     *
     * @param  bool  $follow  Whether to follow redirects.
     * @param  int  $max  The maximum number of redirects to follow.
     * @return self For fluent method chaining.
     */
    public function redirects(bool $follow = true, int $max = 5): self
    {
        $new = clone $this;
        $new->followRedirects = $follow;
        $new->maxRedirects = $max;

        return $new;
    }

    /**
     * Enable and configure automatic retries on failure.
     *
     * @param  int  $maxRetries  Maximum number of retry attempts.
     * @param  float  $baseDelay  Initial delay in seconds before the first retry.
     * @param  float  $backoffMultiplier  Multiplier for exponential backoff (e.g., 2.0).
     * @return self For fluent method chaining.
     */
    public function retry(int $maxRetries = 3, float $baseDelay = 1.0, float $backoffMultiplier = 2.0): self
    {
        $new = clone $this;
        $new->retryConfig = new RetryConfig(
            maxRetries: $maxRetries,
            baseDelay: $baseDelay,
            backoffMultiplier: $backoffMultiplier
        );

        return $new;
    }

    /**
     * Configure retries using a custom RetryConfig object.
     *
     * @param  RetryConfig  $config  The retry configuration object.
     * @return self For fluent method chaining.
     */
    public function retryWith(RetryConfig $config): self
    {
        $new = clone $this;
        $new->retryConfig = $config;

        return $new;
    }

    /**
     * Disable automatic retries for this request.
     *
     * @return self For fluent method chaining.
     */
    public function noRetry(): self
    {
        $new = clone $this;
        $new->retryConfig = null;

        return $new;
    }

    /**
     * Configure SSL certificate verification.
     *
     * @param  bool  $verify  Whether to verify the peer's SSL certificate.
     * @return self For fluent method chaining.
     */
    public function verifySSL(bool $verify = true): self
    {
        $new = clone $this;
        $new->verifySSL = $verify;

        return $new;
    }

    /**
     * Set the User-Agent header for the request.
     *
     * @param  string  $userAgent  The User-Agent string.
     * @return self For fluent method chaining.
     */
    public function withUserAgent(string $userAgent): self
    {
        $new = clone $this;
        $new->userAgent = $userAgent;

        return $new;
    }

    /**
     * Set the request body from a string.
     *
     * @param  string  $content  The raw string content for the body.
     * @return self For fluent method chaining.
     */
    public function body(string $content): self
    {
        $stream = $this->createTempStream();
        $stream->write($content);
        $stream->rewind();

        return $this->withBody($stream);
    }

    /**
     * Set the request body as JSON.
     * Automatically sets the Content-Type header to 'application/json'.
     *
     * @param  array<string, mixed>  $data  The data to be JSON-encoded.
     * @return self For fluent method chaining.
     */
    public function withJson(array $data): self
    {
        $jsonContent = json_encode($data);
        if ($jsonContent === false) {
            throw new InvalidArgumentException('Failed to encode data as JSON');
        }

        return $this->body($jsonContent)->contentType('application/json');
    }

    /**
     * Set the request body as a URL-encoded form.
     * Automatically sets the Content-Type header to 'application/x-www-form-urlencoded'.
     *
     * @param  array<string, mixed>  $data  The form data.
     * @return self For fluent method chaining.
     */
    public function withForm(array $data): self
    {
        return $this->body(http_build_query($data))
            ->contentType('application/x-www-form-urlencoded')
        ;
    }

    /**
     * Set the request body as multipart/form-data.
     *
     * @param  array<string, mixed>  $data  The multipart data.
     * @return self For fluent method chaining.
     */
    public function withMultipart(array $data): self
    {
        $new = clone $this;
        $new->body = $this->createTempStream();
        $new->options['multipart'] = $data;
        $new = $new->withoutHeader('Content-Type');

        return $new;
    }

    /**
     * Configure what type of data SSE events should return.
     *
     * @param string $format The data format to return:
     *                          - 'json': Parse event data as JSON (fallback to raw string)
     *                          - 'array': Convert entire event to array using toArray()
     *                          - 'raw': Return raw event data string
     *                          - 'event': Return full SSEEvent object (default)
     * @return self For fluent method chaining
     */
    public function sseDataFormat(string $format = 'json'): self
    {
        $new = clone $this;
        $new->sseDataFormat = $format;

        return $new;
    }

    /**
     * Create an SSE connection with configured data format.
     *
     * @param string $url The SSE endpoint URL
     * @param (callable(mixed): void)|null $onEvent Callback for each event (receives data in configured format)
     * @param callable(string): void|null $onError Optional callback for connection errors
     * @param SSEReconnectConfig|null $reconnectConfig Optional reconnection configuration
     * @return CancellablePromiseInterface<SSEResponse>
     */
    public function sse(
        string $url,
        ?callable $onEvent = null,
        ?callable $onError = null,
        ?SSEReconnectConfig $reconnectConfig = null
    ): CancellablePromiseInterface {
        $method = $this->body->getSize() > 0 ? 'POST' : 'GET';
        $options = $this->buildCurlOptions($method, $url);

        $effectiveReconnectConfig = $reconnectConfig ?? $this->sseReconnectConfig;
        $wrappedCallback = $this->wrapSSECallback($onEvent);

        return $this->handler->sse($url, $options, $wrappedCallback, $onError, $effectiveReconnectConfig);
    }

    /**
     * Add a custom mapper function to transform SSE event data.
     *
     * @param callable(mixed): mixed $mapper Function to transform the event data
     * @return self For fluent method chaining
     */
    public function sseMap(callable $mapper): self
    {
        $new = clone $this;
        $new->sseMapper = $mapper;

        return $new;
    }

    /**
     * Configure SSE reconnection behavior.
     *
     * @param  bool  $enabled  Whether reconnection is enabled
     * @param  int  $maxAttempts  Maximum reconnection attempts
     * @param  float  $initialDelay  Initial delay before first reconnection
     * @param  float  $maxDelay  Maximum delay between attempts
     * @param  float  $backoffMultiplier  Exponential backoff multiplier
     * @param  bool  $jitter  Add random jitter to delays
     * @param  list<string>  $retryableErrors  List of retryable error messages
     * @param  (callable(int, float, \Throwable): void)|null  $onReconnect  Callback called before each reconnection attempt
     * @param  (callable(\Exception): bool)|null  $shouldReconnect  Custom logic to determine if reconnection should occur
     * @return self For fluent method chaining.
     */
    public function sseReconnect(
        bool $enabled = true,
        int $maxAttempts = 10,
        float $initialDelay = 1.0,
        float $maxDelay = 30.0,
        float $backoffMultiplier = 2.0,
        bool $jitter = true,
        array $retryableErrors = [
            'Connection refused',
            'Connection reset',
            'Connection timed out',
            'Could not resolve host',
            'Resolving timed out',
            'SSL connection timeout',
            'Operation timed out',
            'Network is unreachable',
        ],
        ?callable $onReconnect = null,
        ?callable $shouldReconnect = null
    ): self {
        $new = clone $this;
        // Ensure $retryableErrors is a list
        $retryableErrorsList = array_values($retryableErrors);
        $new->sseReconnectConfig = new SSEReconnectConfig(
            enabled: $enabled,
            maxAttempts: $maxAttempts,
            initialDelay: $initialDelay,
            maxDelay: $maxDelay,
            backoffMultiplier: $backoffMultiplier,
            jitter: $jitter,
            retryableErrors: $retryableErrorsList,
            onReconnect: $onReconnect,
            shouldReconnect: $shouldReconnect
        );

        return $new;
    }

    /**
     * Configure SSE reconnection using a custom configuration object.
     *
     * @param  SSEReconnectConfig  $config  The reconnection configuration
     * @return self For fluent method chaining.
     */
    public function sseReconnectWith(SSEReconnectConfig $config): self
    {
        $new = clone $this;
        $new->sseReconnectConfig = $config;

        return $new;
    }

    /**
     * Disable SSE reconnection.
     *
     * @return self For fluent method chaining.
     */
    public function noSseReconnect(): self
    {
        $new = clone $this;
        $new->sseReconnectConfig = null;

        return $new;
    }

    /**
     * Streams the response body of a GET request.
     *
     * @param  string  $url  The URL to stream from.
     * @param  (callable(string): void)|null  $onChunk  An optional callback for each data chunk.
     * @return CancellablePromiseInterface<StreamingResponse> A promise that resolves with a StreamingResponse.
     */
    public function stream(string $url, ?callable $onChunk = null): CancellablePromiseInterface
    {
        $options = $this->buildFetchOptions('GET');
        $options['stream'] = true;

        if ($onChunk !== null) {
            $options['on_chunk'] = $onChunk;
        }

        /** @var CancellablePromiseInterface<StreamingResponse> */
        return $this->handler->fetch($url, $options);
    }

    /**
     * {@inheritdoc}
     * @return CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>, protocol_version: string|null, size: int|false}> A promise that resolves with download metadata.
     */
    public function download(string $url, string $destination): CancellablePromiseInterface
    {
        $options = $this->buildCurlOptions('GET', $url);
        $options['retry'] = $this->retryConfig;

        return $this->handler->download($url, $destination, $options);
    }

    /**
     * Streams the response body of a POST request.
     *
     * @param  string  $url  The target URL.
     * @param  mixed|null  $body  The request body.
     * @param  (callable(string): void)|null  $onChunk  An optional callback for each data chunk.
     * @return CancellablePromiseInterface<StreamingResponse> A promise that resolves with a StreamingResponse.
     */
    public function streamPost(string $url, $body = null, ?callable $onChunk = null): CancellablePromiseInterface
    {
        $new = $this;
        if ($body !== null) {
            $new = $new->body($this->convertToString($body));
        }
        $options = $new->buildCurlOptions('POST', $url);
        $options[CURLOPT_HEADER] = false;

        return $this->handler->stream($url, $options, $onChunk);
    }

    /**
     * Performs an asynchronous GET request.
     *
     * @param  string  $url  The target URL.
     * @param  array<string, mixed>  $query  Optional query parameters to append to the URL.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    public function get(string $url, array $query = []): PromiseInterface
    {
        if (count($query) > 0) {
            $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($query);
        }

        return $this->send('GET', $url);
    }

    /**
     * Performs an asynchronous POST request.
     *
     * @param  string  $url  The target URL.
     * @param  array<string, mixed>  $data  If provided, will be JSON-encoded and set as the request body.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
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
     * Performs an asynchronous PUT request.
     *
     * @param  string  $url  The target URL.
     * @param  array<string, mixed>  $data  If provided, will be JSON-encoded and set as the request body.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
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
     * Performs an asynchronous DELETE request.
     *
     * @param  string  $url  The target URL.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    public function delete(string $url): PromiseInterface
    {
        return $this->send('DELETE', $url);
    }

    /**
     * Performs an asynchronous PATCH request.
     *
     * @param  string  $url  The target URL.
     * @param  array<string, mixed>  $data  If provided, will be JSON-encoded and set as the request body.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    public function patch(string $url, array $data = []): PromiseInterface
    {
        $new = $this;
        if (count($data) > 0 && $this->body->getSize() === 0 && ! isset($this->options['multipart'])) {
            $new = $new->withJson($data);
        }

        return $new->send('PATCH', $url);
    }

    /**
     * Performs an asynchronous OPTIONS request.
     *
     * @param  string  $url  The target URL.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    public function options(string $url): PromiseInterface
    {
        return $this->send('OPTIONS', $url);
    }

    /**
     * Performs an asynchronous HEAD request.
     *
     * @param  string  $url  The target URL.
     * @return PromiseInterface<Response> A promise that resolves with a Response object.
     */
    public function head(string $url): PromiseInterface
    {
        return $this->send('HEAD', $url);
    }

    /**
     * Enables caching for this request with a specific Time-To-Live.
     *
     * This enables a zero-config, file-based cache for the request.
     * The underlying handler will automatically manage the cache instance.
     *
     * @param  int  $ttlSeconds  The number of seconds the response should be cached.
     * @param  bool  $respectServerHeaders  If true, the server's `Cache-Control: max-age` header will override the provided TTL.
     * @return self For fluent method chaining.
     */
    public function cache(int $ttlSeconds = 3600, bool $respectServerHeaders = true): self
    {
        $new = clone $this;
        $new->cacheConfig = new CacheConfig($ttlSeconds, $respectServerHeaders);

        return $new;
    }

    /**
     * Enables caching for this request using a custom configuration object.
     *
     * This method is for advanced use cases where you need to provide a specific
     * cache implementation (e.g., Redis, Memcached) or more complex rules.
     *
     * @param  CacheConfig  $config  The custom caching configuration object.
     * @return self For fluent method chaining.
     */
    public function cacheWith(CacheConfig $config): self
    {
        $new = clone $this;
        $new->cacheConfig = $config;

        return $new;
    }

    /**
     * Enables caching with a custom cache key.
     *
     * @param  string  $cacheKey  The custom cache key to use
     * @param  int  $ttlSeconds  The number of seconds the response should be cached.
     * @param  bool  $respectServerHeaders  If true, server's Cache-Control will override TTL.
     * @return self For fluent method chaining.
     */
    public function cacheWithKey(string $cacheKey, int $ttlSeconds = 3600, bool $respectServerHeaders = true): self
    {
        $new = clone $this;
        $new->cacheConfig = new CacheConfig($ttlSeconds, $respectServerHeaders, null, $cacheKey);

        return $new;
    }

    /**
     * Add a file to the multipart request.
     *
     * @param string $name The form field name
     * @param string|UploadedFileInterface|resource $file File path, UploadedFile, or resource
     * @param string|null $filename Optional filename override
     * @param string|null $contentType Optional content type override
     * @return self For fluent method chaining.
     */
    public function withFile(string $name, $file, ?string $filename = null, ?string $contentType = null): self
    {
        $new = clone $this;
        if (! isset($new->options['multipart'])) {
            $new->options['multipart'] = [];
        }

        $multipart = $new->options['multipart'];
        if (! is_array($multipart)) {
            $multipart = [];
        }

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
            $mimeType = mime_content_type($file);
            $multipart[$name] = [
                'name' => $name,
                'contents' => $resource,
                'filename' => $filename ?? basename($file),
                'Content-Type' => $contentType ?? ($mimeType !== false ? $mimeType : 'application/octet-stream'),
            ];
        } elseif (is_resource($file)) {
            $multipart[$name] = [
                'name' => $name,
                'contents' => $file,
                'filename' => $filename ?? 'file',
                'Content-Type' => $contentType ?? 'application/octet-stream',
            ];
        } else {
            throw new InvalidArgumentException('File must be a file path, UploadedFileInterface, or resource');
        }

        $new->options['multipart'] = $multipart;
        $new = $new->withoutHeader('Content-Type');

        return $new;
    }

    /**
     * Add multiple files to the multipart request.
     *
     * @param array<string, string|UploadedFileInterface|resource|array{path: string, name?: string, type?: string}> $files Associative array of field names to files
     * @return self For fluent method chaining.
     */
    public function withFiles(array $files): self
    {
        $new = $this;
        foreach ($files as $name => $file) {
            if (is_array($file)) {
                if (! isset($file['path']) || ! is_string($file['path'])) {
                    throw new InvalidArgumentException("File array for '{$name}' must contain a string 'path' key.");
                }

                $filePath = $file['path'];
                $fileName = (isset($file['name']) && is_string($file['name'])) ? $file['name'] : null;
                $fileType = (isset($file['type']) && is_string($file['type'])) ? $file['type'] : null;

                $new = $new->withFile($name, $filePath, $fileName, $fileType);
            } else {
                $new = $new->withFile($name, $file);
            }
        }

        return $new;
    }

    /**
     * Create a multipart form with both data and files.
     *
     * @param array<string, mixed> $data Form data
     * @param array<string, string|UploadedFileInterface|resource|array{path: string, name?: string, type?: string}> $files File data
     * @return self For fluent method chaining.
     */
    public function multipartWithFiles(array $data = [], array $files = []): self
    {
        return $this->withMultipart($data)->withFiles($files);
    }

    /**
     * Dispatches the configured request.
     *
     * @param string $method The HTTP method (GET, POST, etc.).
     * @param string $url The target URL.
     * @return PromiseInterface<Response> A promise that resolves with the final Response object.
     */
    public function send(string $method, string $url): PromiseInterface
    {
        $expandedUrl = $this->expandUriTemplate($url);

        $initialRequest = $this->withMethod($method)->withUri(new Uri($expandedUrl));

        // Process request interceptors
        return $this->getRequestInterceptorHandler()
            ->processInterceptors($initialRequest, $this->requestInterceptors)
            ->then(
                fn ($processedRequest) => $this->executeRequest($processedRequest)
            )
        ;
    }

    /**
     * Add a single cookie to this request (sent as Cookie header).
     *
     * @param  string  $name  Cookie name
     * @param  string  $value  Cookie value
     * @return self For fluent method chaining.
     */
    public function withCookie(string $name, string $value): self
    {
        $existingCookies = $this->getHeaderLine('Cookie');
        $newCookie = $name . '=' . urlencode($value);

        if ($existingCookies !== '') {
            return $this->withHeader('Cookie', $existingCookies . '; ' . $newCookie);
        } else {
            return $this->withHeader('Cookie', $newCookie);
        }
    }

    /**
     * Add multiple cookies at once.
     *
     * @param  array<string, string>  $cookies  An associative array of cookie names to values.
     * @return self For fluent method chaining.
     */
    public function withCookies(array $cookies): self
    {
        $new = $this;
        foreach ($cookies as $name => $value) {
            $new = $new->withCookie($name, $value);
        }

        return $new;
    }

    /**
     * Enable automatic cookie management with an in-memory cookie jar.
     * Cookies from responses will be automatically stored and sent in subsequent requests.
     *
     * @return self For fluent method chaining.
     */
    public function withCookieJar(): self
    {
        return $this->useCookieJar(new CookieJar());
    }

    /**
     * Enable automatic cookie management with a file-based cookie jar.
     *
     * @param  string  $filename  The file path to store cookies.
     * @param  bool  $includeSessionCookies  Whether to persist session cookies (cookies without expiration).
     * @return self For fluent method chaining.
     */
    public function withFileCookieJar(string $filename, bool $includeSessionCookies = false): self
    {
        return $this->useCookieJar(new FileCookieJar($filename, $includeSessionCookies));
    }

    /**
     * Use a custom cookie jar for automatic cookie management.
     *
     * @param  CookieJarInterface  $cookieJar  The cookie jar to use.
     * @return self For fluent method chaining.
     */
    public function useCookieJar(CookieJarInterface $cookieJar): self
    {
        $new = clone $this;
        $new->cookieJar = $cookieJar;

        return $new;
    }

    /**
     * Convenience: Enable file-based cookie storage including session cookies.
     * Perfect for testing or when you want to persist all cookies.
     *
     * @param  string  $filename  The file path to store cookies.
     * @return self For fluent method chaining.
     */
    public function withAllCookiesSaved(string $filename): self
    {
        return $this->withFileCookieJar($filename, true);
    }

    /**
     * Clear all cookies from the current cookie jar (if any).
     *
     * @return self For fluent method chaining.
     */
    public function clearCookies(): self
    {
        $new = clone $this;
        if ($new->cookieJar !== null) {
            $new->cookieJar->clear();
        }

        return $new;
    }

    /**
     * Get the current cookie jar instance.
     *
     * @return CookieJarInterface|null The current cookie jar or null if none is set.
     */
    public function getCookieJar(): ?CookieJarInterface
    {
        return $this->cookieJar;
    }

    /**
     * Set a cookie with additional attributes.
     *
     * @param  string  $name  Cookie name
     * @param  string  $value  Cookie value
     * @param  array<string, mixed>  $attributes  Additional cookie attributes (domain, path, expires, etc.)
     * @return self For fluent method chaining.
     */
    public function cookieWithAttributes(string $name, string $value, array $attributes = []): self
    {
        $new = clone $this;
        if ($new->cookieJar === null) {
            $new->cookieJar = new CookieJar();
        }

        $cookie = new Cookie(
            $name,
            $value,
            isset($attributes['expires']) && is_numeric($attributes['expires']) ? (int)$attributes['expires'] : null,
            isset($attributes['domain']) && is_string($attributes['domain']) ? $attributes['domain'] : null,
            isset($attributes['path']) && is_string($attributes['path']) ? $attributes['path'] : null,
            isset($attributes['secure']) ? (bool)$attributes['secure'] : false,
            isset($attributes['httpOnly']) ? (bool)$attributes['httpOnly'] : false,
            isset($attributes['maxAge']) && is_numeric($attributes['maxAge']) ? (int)$attributes['maxAge'] : null,
            isset($attributes['sameSite']) && is_string($attributes['sameSite']) ? $attributes['sameSite'] : null
        );

        $new->cookieJar->setCookie($cookie);

        return $new;
    }

    /**
     * Configure HTTP proxy for this request.
     *
     * @param  string  $host  The proxy host
     * @param  int  $port  The proxy port
     * @param  string|null  $username  Optional proxy username
     * @param  string|null  $password  Optional proxy password
     * @return self For fluent method chaining.
     */
    public function withProxy(string $host, int $port, ?string $username = null, ?string $password = null): self
    {
        $new = clone $this;
        $new->proxyConfig = ProxyConfig::http($host, $port, $username, $password);

        return $new;
    }

    /**
     * Configure SOCKS4 proxy for this request.
     *
     * @param  string  $host  The proxy host
     * @param  int  $port  The proxy port
     * @param  string|null  $username  Optional proxy username
     * @return self For fluent method chaining.
     */
    public function withSocks4Proxy(string $host, int $port, ?string $username = null): self
    {
        $new = clone $this;
        $new->proxyConfig = ProxyConfig::socks4($host, $port, $username);

        return $new;
    }

    /**
     * Configure SOCKS5 proxy for this request.
     *
     * @param  string  $host  The proxy host
     * @param  int  $port  The proxy port
     * @param  string|null  $username  Optional proxy username
     * @param  string|null  $password  Optional proxy password
     * @return self For fluent method chaining.
     */
    public function withSocks5Proxy(string $host, int $port, ?string $username = null, ?string $password = null): self
    {
        $new = clone $this;
        $new->proxyConfig = ProxyConfig::socks5($host, $port, $username, $password);

        return $new;
    }

    /**
     * Configure proxy using a ProxyConfig object.
     *
     * @param  ProxyConfig  $config  The proxy configuration
     * @return self For fluent method chaining.
     */
    public function proxyWith(ProxyConfig $config): self
    {
        $new = clone $this;
        $new->proxyConfig = $config;

        return $new;
    }

    /**
     * Disable proxy for this request.
     *
     * @return self For fluent method chaining.
     */
    public function noProxy(): self
    {
        $new = clone $this;
        $new->proxyConfig = null;

        return $new;
    }

    /**
     * Set the HTTP version for negotiation.
     *
     * @param  string  $version  The HTTP version ('1.0', '1.1', '2.0', '2', '3.0', '3')
     * @return self For fluent method chaining.
     */
    public function httpVersion(string $version): self
    {
        return $this->withProtocolVersion($version);
    }

    /**
     * Force HTTP/1.1 protocol version.
     *
     * @return self For fluent method chaining.
     */
    public function http1(): self
    {
        return $this->withProtocolVersion('1.1');
    }

    /**
     * Force HTTP/2 negotiation with fallback to HTTP/1.1.
     *
     * @return self For fluent method chaining.
     */
    public function http2(): self
    {
        return $this->withProtocolVersion('2.0');
    }

    /**
     * Force HTTP/3 negotiation with fallback to HTTP/1.1.
     *
     * @return self For fluent method chaining.
     */
    public function http3(): self
    {
        return $this->withProtocolVersion('3.0');
    }

    /**
     * Add a raw cURL option for advanced customization.
     *
     * This method allows you to set any cURL option directly, providing maximum
     * flexibility for edge cases not covered by the fluent interface.
     *
     * @param  int  $option  The cURL option constant (e.g., CURLOPT_VERBOSE)
     * @param  mixed  $value  The value for the cURL option
     * @return self For fluent method chaining.
     */
    public function withCurlOption(int $option, $value): self
    {
        $new = clone $this;
        $new->options[$option] = $value;

        return $new;
    }

    /**
     * Add multiple raw cURL options at once.
     *
     * @param  array<int, mixed>  $options  Associative array of cURL option constants to values
     * @return self For fluent method chaining.
     */
    public function withCurlOptions(array $options): self
    {
        $new = clone $this;
        foreach ($options as $option => $value) {
            if (is_int($option)) {
                $new->options[$option] = $value;
            }
        }

        return $new;
    }

    /**
     * Set URL parameters for URI template substitution.
     *
     * Supports both simple substitution {param} and reserved expansion {+param}.
     * Reserved expansion preserves special characters in the value.
     *
     * @param  array<string, mixed>  $parameters  The URL parameters
     * @return self For fluent method chaining.
     */
    public function withUrlParameters(array $parameters): self
    {
        $new = clone $this;
        $new->urlParameters = array_merge($new->urlParameters, $parameters);

        return $new;
    }

    /**
     * Set a single URL parameter for URI template substitution.
     *
     * @param  string  $key  The parameter name
     * @param  mixed  $value  The parameter value
     * @return self For fluent method chaining.
     */
    public function withUrlParameter(string $key, $value): self
    {
        $new = clone $this;
        $new->urlParameters[$key] = $value;

        return $new;
    }

    /**
     * Expand URI template with configured URL parameters.
     *
     * Supports:
     * - Simple expansion: {param}
     * - Reserved expansion: {+param} (preserves special chars like /, ?, #)
     *
     * @param  string  $template  The URI template
     * @return string The expanded URI
     */
    private function expandUriTemplate(string $template): string
    {
        if ($this->urlParameters === []) {
            return $template;
        }

        $result = preg_replace_callback(
            '/\{([+]?)([a-zA-Z0-9_]+)\}/',
            function ($matches) {
                $reserved = $matches[1] === '+';
                $key = $matches[2];

                if (! isset($this->urlParameters[$key])) {
                    return $matches[0];
                }

                $paramValue = $this->urlParameters[$key];

                if (
                    is_scalar($paramValue)
                    || (is_object($paramValue)
                        && method_exists($paramValue, '__toString'))
                ) {
                    $value = (string) $paramValue;
                } else {
                    return $matches[0];
                }

                if ($reserved) {
                    return $value;
                }

                return rawurlencode($value);
            },
            $template
        );

        return $result ?? $template;
    }

    /**
     * Updates the Host header from the URI if necessary.
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
     * Execute the actual request after all interceptors have been processed.
     *
     * @param Request $processedRequest The request after interceptor processing.
     * @return PromiseInterface<Response> A promise that resolves with the response.
     */
    private function executeRequest(Request $processedRequest): PromiseInterface
    {
        $options = $processedRequest->buildCurlOptions(
            $processedRequest->getMethod(),
            (string) $processedRequest->getUri()
        );

        $httpPromise = $this->handler->sendRequest(
            (string) $processedRequest->getUri(),
            $options,
            $processedRequest->cacheConfig,
            $processedRequest->retryConfig
        );

        if (count($processedRequest->responseInterceptors) === 0) {
            return $httpPromise;
        }

        return $httpPromise->then(
            fn ($response) => $this->getResponseInterceptorHandler()
                ->processInterceptors($response, $processedRequest->responseInterceptors)
        );
    }

    /**
     * Build cURL options using the OptionsBuilder.
     *
     * @param  string  $method  The HTTP method.
     * @param  string  $url  The target URL.
     * @return array<int|string, mixed> The final cURL options array.
     */
    private function buildCurlOptions(string $method, string $url): array
    {
        // Cast body to Stream for type safety
        $body = $this->body instanceof Stream ? $this->body : $this->createTempStream();

        return $this->optionsBuilder->buildCurlOptions(
            method: $method,
            url: $url,
            headers: $this->headers,
            body: $body,
            timeout: $this->timeout,
            connectTimeout: $this->connectTimeout,
            followRedirects: $this->followRedirects,
            maxRedirects: $this->maxRedirects,
            verifySSL: $this->verifySSL,
            userAgent: $this->userAgent,
            protocol: $this->protocol,
            cookieJar: $this->cookieJar,
            handlerCookieJar: $this->handler->getCookieJar(),
            proxyConfig: $this->proxyConfig,
            auth: $this->auth,
            additionalOptions: $this->options
        );
    }

    /**
     * Build fetch options using the OptionsBuilder.
     *
     * @param  string  $method  The HTTP method.
     * @return array<string, mixed> The fetch options array.
     */
    private function buildFetchOptions(string $method): array
    {
        $body = $this->body instanceof Stream ? $this->body : $this->createTempStream();

        return $this->optionsBuilder->buildFetchOptions(
            method: $method,
            headers: $this->headers,
            body: $body,
            timeout: $this->timeout,
            connectTimeout: $this->connectTimeout,
            followRedirects: $this->followRedirects,
            maxRedirects: $this->maxRedirects,
            verifySSL: $this->verifySSL,
            userAgent: $this->userAgent,
            auth: $this->auth,
            retryConfig: $this->retryConfig
        );
    }

    /**
     * Wrap the SSE event callback to return data in the configured format.
     *
     * @param callable(mixed): void|null $originalCallback
     * @return callable(SSEEvent): void|null
     */
    private function wrapSSECallback(?callable $originalCallback): ?callable
    {
        if ($originalCallback === null || $this->sseDataFormat === null) {
            return $originalCallback;
        }

        return function (SSEEvent $event) use ($originalCallback): void {
            $processedData = match ($this->sseDataFormat) {
                'json' => $this->parseEventDataAsJson($event),
                'array' => $this->eventToArrayWithParsedData($event),
                'raw' => $event->data,
                'event' => $event,
                default => $event
            };

            if ($this->sseMapper !== null) {
                $processedData = ($this->sseMapper)($processedData);
            }

            $originalCallback($processedData);
        };
    }

    /**
     * Convert event to array with automatically parsed JSON data.
     * If not valid JSON, keep original string
     *
     * @return array<string, mixed>
     */
    private function eventToArrayWithParsedData(SSEEvent $event): array
    {
        $array = $event->toArray();

        if ($array['data'] !== null && is_string($array['data'])) {
            $parsed = json_decode($array['data'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                $array['data'] = $parsed;
            }
        }

        return $array;
    }

    /**
     * Parse event data as JSON, fallback to raw data.
     */
    private function parseEventDataAsJson(SSEEvent $event): mixed
    {
        if ($event->data === null) {
            return null;
        }

        $parsed = json_decode($event->data, true);

        return json_last_error() === JSON_ERROR_NONE ? $parsed : $event->data;
    }

    /**
     * Get or create the request interceptor handler.
     */
    private function getRequestInterceptorHandler(): RequestInterceptorHandler
    {
        if ($this->requestInterceptorHandler === null) {
            $this->requestInterceptorHandler = new RequestInterceptorHandler();
        }

        return $this->requestInterceptorHandler;
    }

    /**
     * Get or create the response interceptor handler.
     */
    private function getResponseInterceptorHandler(): ResponseInterceptorHandler
    {
        if ($this->responseInterceptorHandler === null) {
            $this->responseInterceptorHandler = new ResponseInterceptorHandler();
        }

        return $this->responseInterceptorHandler;
    }
}
