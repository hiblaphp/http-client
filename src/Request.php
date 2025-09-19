<?php

namespace Hibla\Http;

use Hibla\Http\Handlers\HttpHandler;
use Hibla\Http\Interfaces\CompleteHttpClientInterface;
use Hibla\Http\Interfaces\CookieJarInterface;
use Hibla\Http\SSE\SSEEvent;
use Hibla\Http\SSE\SSEReconnectConfig;
use Hibla\Http\SSE\SSEResponse;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * A fluent, chainable, asynchronous HTTP request builder.
 *
 * This class provides a rich interface for constructing and sending HTTP requests
 * asynchronously. It supports setting headers, body, timeouts, authentication,
 * and retry logic in a clean, readable way.
 */
class Request extends Message implements CompleteHttpClientInterface
{
    private HttpHandler $handler;
    private ?CookieJarInterface $cookieJar = null;
    private string $method = 'GET';
    private ?string $requestTarget = null;
    private UriInterface $uri;
    /** @var array<string, mixed> */
    private array $options = [];
    private int $timeout = 30;
    private int $connectTimeout = 10;
    private bool $followRedirects = true;
    private int $maxRedirects = 5;
    private bool $verifySSL = true;
    private ?string $userAgent = null;
    /** @var array{string, string, string}|null */
    private ?array $auth = null;
    private ?RetryConfig $retryConfig = null;
    private ?CacheConfig $cacheConfig = null;
    /** @var callable[] Callbacks to intercept the request before it is sent. */
    private array $requestInterceptors = [];

    /** @var callable[] Callbacks to intercept the response after it is received. */
    private array $responseInterceptors = [];
    private ?ProxyConfig $proxyConfig = null;
    private ?SSEReconnectConfig $sseReconnectConfig = null;
    private ?string $sseDataFormat = null;
    /** @var callable(SSEEvent): array|string|null */
    private $sseMapper = null;

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
    public function __construct(HttpHandler $handler, string $method = 'GET', $uri = '', array $headers = [], $body = null, string $version = '1.1')
    {
        $this->handler = $handler;
        $this->method = strtoupper($method);
        $this->uri = $uri instanceof UriInterface ? $uri : new Uri($uri);
        $this->setHeaders($headers);
        $this->protocol = $version;
        $this->userAgent = 'FiberAsync-HTTP-Client';

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
    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        if ($uri === $this->uri) {
            return $this;
        }

        $new = clone $this;
        $new->uri = $uri;

        if (! $preserveHost || ! isset($this->headerNames['host'])) {
            $new->updateHostFromUri();
        }

        return $new;
    }

    /**
     * Set multiple headers at once.
     *
     * @param  array<string, string>  $headers  An associative array of header names to values.
     * @return self For fluent method chaining.
     */
    public function headers(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }

        return $this;
    }

    /**
     * Set a single header.
     *
     * @param  string  $name  The header name.
     * @param  string  $value  The header value.
     * @return self For fluent method chaining.
     */
    public function header(string $name, string $value): self
    {
        $normalized = strtolower($name);

        if (isset($this->headerNames[$normalized])) {
            $originalName = $this->headerNames[$normalized];
            $this->headers[$originalName] = [$value];
        } else {
            $this->headerNames[$normalized] = $name;
            $this->headers[$name] = [$value];
        }

        return $this;
    }

    /**
     * Set the Content-Type header.
     *
     * @param  string  $type  The media type (e.g., 'application/json').
     * @return self For fluent method chaining.
     */
    public function contentType(string $type): self
    {
        return $this->header('Content-Type', $type);
    }

    /**
     * Set the Accept header.
     *
     * @param  string  $type  The desired media type (e.g., 'application/json').
     * @return self For fluent method chaining.
     */
    public function accept(string $type): self
    {
        return $this->header('Accept', $type);
    }

    /**
     * Attach a bearer token to the Authorization header.
     *
     * @param  string  $token  The bearer token.
     * @return self For fluent method chaining.
     */
    public function bearerToken(string $token): self
    {
        return $this->header('Authorization', "Bearer {$token}");
    }

    /**
     * Set basic authentication credentials.
     *
     * @param  string  $username  The username.
     * @param  string  $password  The password.
     * @return self For fluent method chaining.
     */
    public function basicAuth(string $username, string $password): self
    {
        $this->auth = ['basic', $username, $password];

        return $this;
    }

    /**
     * Set the total request timeout in seconds.
     *
     * @param  int  $seconds  The timeout duration.
     * @return self For fluent method chaining.
     */
    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Set the connection timeout in seconds.
     *
     * @param  int  $seconds  The timeout duration for the connection phase.
     * @return self For fluent method chaining.
     */
    public function connectTimeout(int $seconds): self
    {
        $this->connectTimeout = $seconds;

        return $this;
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
        $this->followRedirects = $follow;
        $this->maxRedirects = $max;

        return $this;
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
        $this->retryConfig = new RetryConfig(
            maxRetries: $maxRetries,
            baseDelay: $baseDelay,
            backoffMultiplier: $backoffMultiplier
        );

        return $this;
    }

    /**
     * Configure retries using a custom RetryConfig object.
     *
     * @param  RetryConfig  $config  The retry configuration object.
     * @return self For fluent method chaining.
     */
    public function retryWith(RetryConfig $config): self
    {
        $this->retryConfig = $config;

        return $this;
    }

    /**
     * Disable automatic retries for this request.
     *
     * @return self For fluent method chaining.
     */
    public function noRetry(): self
    {
        $this->retryConfig = null;

        return $this;
    }

    /**
     * Configure SSL certificate verification.
     *
     * @param  bool  $verify  Whether to verify the peer's SSL certificate.
     * @return self For fluent method chaining.
     */
    public function verifySSL(bool $verify = true): self
    {
        $this->verifySSL = $verify;

        return $this;
    }

    /**
     * Set the User-Agent header for the request.
     *
     * @param  string  $userAgent  The User-Agent string.
     * @return self For fluent method chaining.
     */
    public function userAgent(string $userAgent): self
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    /**
     * Set the request body from a string.
     *
     * @param  string  $content  The raw string content for the body.
     * @return self For fluent method chaining.
     */
    public function body(string $content): self
    {
        $this->body = $this->createTempStream();
        $this->body->write($content);
        $this->body->rewind();

        return $this;
    }

    /**
     * Set the request body as JSON.
     * Automatically sets the Content-Type header to 'application/json'.
     *
     * @param  array<string, mixed>  $data  The data to be JSON-encoded.
     * @return self For fluent method chaining.
     */
    public function json(array $data): self
    {
        $jsonContent = json_encode($data);
        if ($jsonContent === false) {
            throw new \InvalidArgumentException('Failed to encode data as JSON');
        }
        $this->body($jsonContent);
        $this->contentType('application/json');

        return $this;
    }

    /**
     * Set the request body as a URL-encoded form.
     * Automatically sets the Content-Type header to 'application/x-www-form-urlencoded'.
     *
     * @param  array<string, mixed>  $data  The form data.
     * @return self For fluent method chaining.
     */
    public function form(array $data): self
    {
        $this->body(http_build_query($data));
        $this->contentType('application/x-www-form-urlencoded');

        return $this;
    }

    /**
     * Set the request body as multipart/form-data.
     *
     * @param  array<string, mixed>  $data  The multipart data.
     * @return self For fluent method chaining.
     */
    public function multipart(array $data): self
    {
        $this->body = $this->createTempStream();
        $this->options['multipart'] = $data;
        unset($this->headers['content-type']);

        return $this;
    }

    /**
     * Configure what type of data SSE events should return.
     *
     * @param string $dataFormat The data format to return:
     *                          - 'json': Parse event data as JSON (fallback to raw string)
     *                          - 'array': Convert entire event to array using toArray()  
     *                          - 'raw': Return raw event data string
     *                          - 'event': Return full SSEEvent object (default)
     * @return self For fluent method chaining
     */
    public function sseDataFormat(string $format = 'array'): self
    {
        $this->sseDataFormat = $format;
        return $this;
    }

    /**
     * Create an SSE connection with configured data format.
     *
     * @param string $url The SSE endpoint URL
     * @param callable $onEvent Callback for each event (receives data in configured format)
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
        $options = $this->buildFetchOptions('GET');
        unset($options['timeout']);

        $effectiveReconnectConfig = $reconnectConfig ?? $this->sseReconnectConfig;
        $wrappedCallback = $this->wrapSSECallback($onEvent);

        return $this->handler->sse($url, $options, $wrappedCallback, $onError, $effectiveReconnectConfig);
    }

    /**
     * Add a custom mapper function to transform SSE event data.
     *
     * @param callable $mapper Function to transform the event data: function($data): mixed
     * @return self For fluent method chaining
     */
    public function sseMap(callable $mapper): self
    {
        $this->sseMapper = $mapper;
        return $this;
    }

    /**
     * Wrap the SSE event callback to return data in the configured format.
     */
    private function wrapSSECallback(?callable $originalCallback): ?callable
    {
        if ($originalCallback === null || $this->sseDataFormat === null) {
            return $originalCallback;
        }

        return function (SSEEvent $event) use ($originalCallback) {
            $processedData = match ($this->sseDataFormat) {
                'json' => $this->parseEventDataAsJson($event),
                'array' => $this->eventToArrayWithParsedData($event),
                'raw' => $event->data,
                'event' => $event,
                default => $event
            };

            if ($this->sseMapper !== null) {
                $processedData = call_user_func($this->sseMapper, $processedData);
            }

            $originalCallback($processedData);
        };
    }

    /**
     * Convert event to array with automatically parsed JSON data.
     * If not valid JSON, keep original string
     */
    private function eventToArrayWithParsedData(SSEEvent $event): array
    {
        $array = $event->toArray();

        if ($array['data'] !== null) {
            $parsed = json_decode($array['data'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
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
     * Configure SSE reconnection behavior.
     *
     * @param  bool  $enabled  Whether reconnection is enabled
     * @param  int  $maxAttempts  Maximum reconnection attempts
     * @param  float  $initialDelay  Initial delay before first reconnection
     * @param  float  $maxDelay  Maximum delay between attempts
     * @param  float  $backoffMultiplier  Exponential backoff multiplier
     * @param  bool  $jitter  Add random jitter to delays
     * @param  array  $retryableErrors  List of retryable error messages
     * @param  callable|null  $onReconnect  Callback called before each reconnection attempt
     * @param  callable|null  $shouldReconnect  Custom logic to determine if reconnection should occur
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
        $this->sseReconnectConfig = new SSEReconnectConfig(
            enabled: $enabled,
            maxAttempts: $maxAttempts,
            initialDelay: $initialDelay,
            maxDelay: $maxDelay,
            backoffMultiplier: $backoffMultiplier,
            jitter: $jitter,
            retryableErrors: $retryableErrors,
            onReconnect: $onReconnect,
            shouldReconnect: $shouldReconnect
        );

        return $this;
    }

    /**
     * Configure SSE reconnection using a custom configuration object.
     *
     * @param  SSEReconnectConfig  $config  The reconnection configuration
     */
    public function sseReconnectWith(SSEReconnectConfig $config): self
    {
        $this->sseReconnectConfig = $config;

        return $this;
    }

    /**
     * Disable SSE reconnection.
     */
    public function noSseReconnect(): self
    {
        $this->sseReconnectConfig = null;

        return $this;
    }

    /**
     * Streams the response body of a GET request.
     *
     * @param  string  $url  The URL to stream from.
     * @param  callable|null  $onChunk  An optional callback for each data chunk. `function(string $chunk): void`
     * @return CancellablePromiseInterface<StreamingResponse> A promise that resolves with a StreamingResponse.
     */
    public function stream(string $url, ?callable $onChunk = null): CancellablePromiseInterface
    {
        $options = $this->buildFetchOptions('GET');
        $options['stream'] = true;

        if ($onChunk) {
            $options['on_chunk'] = $onChunk;
        }

        return $this->handler->fetch($url, $options);
    }

    /**
     * Downloads a file from a URL to a local destination.
     *
     * @param  string  $url  The URL of the file to download.
     * @param  string  $destination  The local file path to save to.
     * @return CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>}> A promise that resolves with download metadata.
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
     * @param  callable|null  $onChunk  An optional callback for each data chunk. `function(string $chunk): void`
     * @return CancellablePromiseInterface<StreamingResponse> A promise that resolves with a StreamingResponse.
     */
    public function streamPost(string $url, $body = null, ?callable $onChunk = null): CancellablePromiseInterface
    {
        if ($body !== null) {
            $this->body($this->convertToString($body));
        }
        $options = $this->buildCurlOptions('POST', $url);
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
        if (count($data) > 0 && $this->body->getSize() === 0 && ! isset($this->options['multipart'])) {
            $this->json($data);
        }

        return $this->send('POST', $url);
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
        if (count($data) > 0 && $this->body->getSize() === 0 && ! isset($this->options['multipart'])) {
            $this->json($data);
        }

        return $this->send('PUT', $url);
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
        $this->cacheConfig = new CacheConfig($ttlSeconds, $respectServerHeaders);

        return $this;
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
        $this->cacheConfig = $config;

        return $this;
    }

    /**
     * Dispatches the configured request.
     *
     * This method builds the final cURL options and sends the request via the
     * HttpHandler, which will apply caching and/or retry logic as configured.
     *
     * @param  string  $method  The HTTP method (GET, POST, etc.).
     * @param  string  $url  The target URL.
     * @return PromiseInterface<Response> A promise that resolves with the final Response object.
     */
    public function send(string $method, string $url): PromiseInterface
    {
        if (empty($this->requestInterceptors) && empty($this->responseInterceptors)) {
            $options = $this->buildCurlOptions($method, $url);

            return $this->handler->sendRequest($url, $options, $this->cacheConfig, $this->retryConfig);
        }

        // Process request interceptors immediately (they should be synchronous)
        $processedRequest = $this->withMethod($method)->withUri(new Uri($url));
        foreach ($this->requestInterceptors as $interceptor) {
            $processedRequest = $interceptor($processedRequest);
        }

        // Build options and send request
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

        // If no response interceptors, return the HTTP promise directly
        if (empty($processedRequest->responseInterceptors)) {
            return $httpPromise;
        }

        // Create a new promise to handle response interceptors sequentially
        $finalPromise = new CancellablePromise(function (callable $resolve, callable $reject) use ($httpPromise, $processedRequest) {
            $httpPromise->then(
                function ($response) use ($processedRequest, $resolve, $reject) {
                    try {
                        // Process response interceptors sequentially
                        $this->processResponseInterceptorsSequentially(
                            $response,
                            $processedRequest->responseInterceptors,
                            $resolve,
                            $reject
                        );
                    } catch (\Throwable $e) {
                        $reject($e);
                    }
                },
                $reject
            );
        });

        // Set up cancellation handler
        $finalPromise->setCancelHandler(function () use ($httpPromise) {
            if ($httpPromise instanceof CancellablePromiseInterface) {
                $httpPromise->cancel();
            }
        });

        return $finalPromise;
    }

    /**
     * Process response interceptors sequentially, handling both sync and async interceptors.
     */
    private function processResponseInterceptorsSequentially(
        Response $response,
        array $interceptors,
        callable $resolve,
        callable $reject
    ): void {
        if (empty($interceptors)) {
            $resolve($response);

            return;
        }

        $interceptor = array_shift($interceptors);

        try {
            $result = $interceptor($response);

            if ($result instanceof PromiseInterface) {
                // Async interceptor - wait for it to complete before processing next
                $result->then(
                    function ($asyncResponse) use ($interceptors, $resolve, $reject) {
                        $this->processResponseInterceptorsSequentially(
                            $asyncResponse,
                            $interceptors,
                            $resolve,
                            $reject
                        );
                    },
                    $reject
                );
            } else {
                // Sync interceptor - process immediately and continue
                $this->processResponseInterceptorsSequentially(
                    $result,
                    $interceptors,
                    $resolve,
                    $reject
                );
            }
        } catch (\Throwable $e) {
            $reject($e);
        }
    }

    /**
     * Add a single cookie to this request (sent as Cookie header).
     *
     * @param  string  $name  Cookie name
     * @param  string  $value  Cookie value
     * @return self For fluent method chaining.
     */
    public function cookie(string $name, string $value): self
    {
        $existingCookies = $this->getHeaderLine('Cookie');
        $newCookie = $name . '=' . urlencode($value);

        if ($existingCookies !== '') {
            return $this->header('Cookie', $existingCookies . '; ' . $newCookie);
        } else {
            return $this->header('Cookie', $newCookie);
        }
    }

    /**
     * Add multiple cookies at once.
     *
     * @param  array<string, string>  $cookies  An associative array of cookie names to values.
     * @return self For fluent method chaining.
     */
    public function cookies(array $cookies): self
    {
        foreach ($cookies as $name => $value) {
            $this->cookie($name, $value);
        }

        return $this;
    }

    /**
     * Enable automatic cookie management with an in-memory cookie jar.
     * Cookies from responses will be automatically stored and sent in subsequent requests.
     *
     * @return self For fluent method chaining.
     */
    public function withCookieJar(): self
    {
        return $this->useCookieJar(new CookieJar);
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
        if ($this->cookieJar !== null) {
            $this->cookieJar->clear();
        }

        return $this;
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
        if ($this->cookieJar === null) {
            $this->cookieJar = new CookieJar;
        }

        $cookie = new Cookie(
            $name,
            $value,
            $attributes['expires'] ?? null,
            $attributes['domain'] ?? null,
            $attributes['path'] ?? null,
            $attributes['secure'] ?? false,
            $attributes['httpOnly'] ?? false,
            $attributes['maxAge'] ?? null,
            $attributes['sameSite'] ?? null
        );

        $this->cookieJar->setCookie($cookie);

        return $this;
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
    public function proxy(string $host, int $port, ?string $username = null, ?string $password = null): self
    {
        $this->proxyConfig = ProxyConfig::http($host, $port, $username, $password);

        return $this;
    }

    /**
     * Configure SOCKS4 proxy for this request.
     *
     * @param  string  $host  The proxy host
     * @param  int  $port  The proxy port
     * @param  string|null  $username  Optional proxy username
     * @return self For fluent method chaining.
     */
    public function socks4Proxy(string $host, int $port, ?string $username = null): self
    {
        $this->proxyConfig = ProxyConfig::socks4($host, $port, $username);

        return $this;
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
    public function socks5Proxy(string $host, int $port, ?string $username = null, ?string $password = null): self
    {
        $this->proxyConfig = ProxyConfig::socks5($host, $port, $username, $password);

        return $this;
    }

    /**
     * Configure proxy using a ProxyConfig object.
     *
     * @param  ProxyConfig  $config  The proxy configuration
     * @return self For fluent method chaining.
     */
    public function proxyWith(ProxyConfig $config): self
    {
        $this->proxyConfig = $config;

        return $this;
    }

    /**
     * Disable proxy for this request.
     *
     * @return self For fluent method chaining.
     */
    public function noProxy(): self
    {
        $this->proxyConfig = null;

        return $this;
    }

    /**
     * Set the HTTP version for negotiation.
     *
     * @param  string  $version  The HTTP version ('1.0', '1.1', '2.0', '2', '3.0', '3')
     * @return self For fluent method chaining.
     */
    public function httpVersion(string $version): self
    {
        $this->protocol = $version;

        return $this;
    }

    /**
     * Force HTTP/2 negotiation with fallback to HTTP/1.1.
     *
     * @return self For fluent method chaining.
     */
    public function http2(): self
    {
        $this->protocol = '2.0';

        return $this;
    }

    /**
     * Force HTTP/3 negotiation with fallback to HTTP/1.1.
     *
     * @return self For fluent method chaining.
     */
    public function http3(): self
    {
        $this->protocol = '3.0';

        return $this;
    }

    /**
     * Builds a high-level "fetch" style options array from the builder's state.
     *
     * @param  string  $method  The HTTP method.
     * @return array<string, mixed> The fetch options array.
     */
    private function buildFetchOptions(string $method): array
    {
        $options = [
            'method' => $method,
            'headers' => [],
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'follow_redirects' => $this->followRedirects,
            'max_redirects' => $this->maxRedirects,
            'verify_ssl' => $this->verifySSL,
            'user_agent' => $this->userAgent,
            'auth' => [],
        ];

        if ($this->retryConfig) {
            $options['retry'] = $this->retryConfig;
        }

        foreach ($this->headers as $name => $values) {
            $options['headers'][$name] = implode(', ', $values);
        }

        if ($this->auth !== null) {
            [$type, $username, $password] = $this->auth;
            if ($type === 'basic') {
                $options['auth']['basic'] = ['username' => $username, 'password' => $password];
            }
        }

        if ($this->body->getSize() > 0) {
            $options['body'] = (string) $this->body;
        }

        return $options;
    }

    /**
     * Compiles all configured options into a cURL options array.
     *
     * @param  string  $method  The HTTP method.
     * @param  string  $url  The target URL.
     * @return array<int, mixed> The final cURL options array.
     */
    private function buildCurlOptions(string $method, string $url): array
    {
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => $this->followRedirects,
            CURLOPT_MAXREDIRS => $this->maxRedirects,
            CURLOPT_SSL_VERIFYPEER => $this->verifySSL,
            CURLOPT_SSL_VERIFYHOST => $this->verifySSL ? 2 : 0,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
        ];

        $options[CURLOPT_HTTP_VERSION] = match ($this->protocol) {
            '2.0', '2' => CURL_HTTP_VERSION_2TLS,
            '3.0', '3' => defined('CURL_HTTP_VERSION_3')
                ? CURL_HTTP_VERSION_3
                : CURL_HTTP_VERSION_1_1,
            '1.0' => CURL_HTTP_VERSION_1_0,
            default => CURL_HTTP_VERSION_1_1,
        };

        $effectiveCookieJar = $this->cookieJar ?? $this->handler->getCookieJar();

        if ($effectiveCookieJar !== null) {
            $uri = new Uri($url);
            $cookieHeader = $effectiveCookieJar->getCookieHeader(
                $uri->getHost(),
                $uri->getPath() !== '' ? $uri->getPath() : '/',
                $uri->getScheme() === 'https'
            );

            if ($cookieHeader !== '') {
                $existingCookies = $this->getHeaderLine('Cookie');
                if ($existingCookies !== '') {
                    $this->header('Cookie', $existingCookies . '; ' . $cookieHeader);
                } else {
                    $this->header('Cookie', $cookieHeader);
                }
            }
        }

        $this->addProxyOptions($options);

        if (strtoupper($method) === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }

        $this->addHeaderOptions($options);
        $this->addBodyOptions($options);
        $this->addAuthenticationOptions($options);

        if ($effectiveCookieJar !== null) {
            $options['_cookie_jar'] = $effectiveCookieJar;
        }

        foreach ($this->options as $key => $value) {
            if (is_int($key)) {
                $options[$key] = $value;
            }
        }

        return $options;
    }

    /**
     * Adds configured proxy settings to the cURL options.
     *
     * @param  array<int, mixed>  &$options  The cURL options array passed by reference.
     */
    private function addProxyOptions(array &$options): void
    {
        if ($this->proxyConfig === null) {
            return;
        }

        $options[CURLOPT_PROXY] = $this->proxyConfig->host . ':' . $this->proxyConfig->port;
        $options[CURLOPT_PROXYTYPE] = $this->proxyConfig->getCurlProxyType();

        if ($this->proxyConfig->username !== null) {
            $proxyAuth = $this->proxyConfig->username;
            if ($this->proxyConfig->password !== null) {
                $proxyAuth .= ':' . $this->proxyConfig->password;
            }
            $options[CURLOPT_PROXYUSERPWD] = $proxyAuth;
        }

        if (in_array($this->proxyConfig->type, ['socks4', 'socks5'])) {
            $options[CURLOPT_HTTPPROXYTUNNEL] = false;
        } else {
            $options[CURLOPT_HTTPPROXYTUNNEL] = true;
        }
    }

    /**
     * Adds configured headers to the cURL options.
     *
     * @param  array<int, mixed>  &$options  The cURL options array passed by reference.
     */
    private function addHeaderOptions(array &$options): void
    {
        if (count($this->headers) > 0) {
            $headerStrings = [];
            foreach ($this->headers as $name => $value) {
                $headerStrings[] = "{$name}: " . implode(', ', $value);
            }
            $options[CURLOPT_HTTPHEADER] = $headerStrings;
        }
    }

    /**
     * Adds the configured body to the cURL options.
     *
     * @param  array<int, mixed>  &$options  The cURL options array passed by reference.
     */
    private function addBodyOptions(array &$options): void
    {
        if (isset($this->options['multipart'])) {
            $options[CURLOPT_POSTFIELDS] = $this->options['multipart'];
        } elseif ($this->body->getSize() > 0) {
            $options[CURLOPT_POSTFIELDS] = (string) $this->body;
        }
    }

    /**
     * Adds configured authentication details to the cURL options.
     *
     * @param  array<int, mixed>  &$options  The cURL options array passed by reference.
     */
    private function addAuthenticationOptions(array &$options): void
    {
        if ($this->auth !== null) {
            [$type, $username, $password] = $this->auth;
            if ($type === 'basic') {
                $options[CURLOPT_USERPWD] = "{$username}:{$password}";
                $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            }
        }
    }

    /**
     * Updates the Host header from the URI if necessary.
     */
    private function updateHostFromUri(): void
    {
        $host = $this->uri->getHost();
        if ($host === '') {
            return;
        }

        if (($port = $this->uri->getPort()) !== null) {
            $host .= ':' . $port;
        }

        if (isset($this->headerNames['host'])) {
            $header = $this->headerNames['host'];
        } else {
            $header = 'Host';
            $this->headerNames['host'] = 'Host';
        }
        $this->headers[$header] = [$host];
    }

    /**
     * Creates a temporary stream resource safely.
     */
    private function createTempStream(): Stream
    {
        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            throw new \RuntimeException('Unable to create temporary stream');
        }

        return new Stream($resource, null);
    }

    /**
     * Safely converts mixed values to string.
     *
     * @param  mixed  $value  The value to convert to string
     */
    private function convertToString($value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value) || is_null($value)) {
            return (string) $value;
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return var_export($value, true);
    }
}
