<?php

namespace Hibla\HttpClient;

use Hibla\HttpClient\Handlers\HttpHandler;
use Hibla\HttpClient\Interfaces\CookieJarInterface;
use Hibla\HttpClient\SSE\SSEReconnectConfig;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\HttpClient\Testing\MockRequestBuilder;
use Hibla\HttpClient\Testing\TestingHttpHandler;
use Hibla\HttpClient\Testing\Utilities\RecordedRequest;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A static API for clean, expressive, and asynchronous HTTP operations.
 *
 * This class provides a simple, static entry point for all HTTP-related tasks,
 * including GET, POST, streaming, and file downloads. It abstracts away the
 * underlying handler and event loop management for a more convenient API.
 *
 * Direct HTTP methods from HttpHandler:
 *
 * @method static PromiseInterface<Response> get(string $url, array<string, mixed> $query = []) Performs a GET request.
 * @method static PromiseInterface<Response> post(string $url, array<string, mixed> $data = []) Performs a POST request.
 * @method static PromiseInterface<Response> put(string $url, array<string, mixed> $data = []) Performs a PUT request.
 * @method static PromiseInterface<Response> delete(string $url) Performs a DELETE request.
 * @method static PromiseInterface<Response> patch(string $url, array<string, mixed> $data = []) Performs a PATCH request.
 * @method static PromiseInterface<Response> options(string $url) Performs an OPTIONS request.
 * @method static PromiseInterface<Response> head(string $url) Performs a HEAD request.
 * @method static PromiseInterface<Response> fetch(string $url, array<int|string, mixed> $options = []) A flexible, fetch-like request method.
 * @method static CancellablePromiseInterface<StreamingResponse> stream(string $url, ?callable $onChunk = null) Streams a response body.
 * @method static CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>}> download(string $url, string $destination) Downloads a file.
 *
 * Request builder methods:
 * @method static Request cache(int $ttlSeconds = 3600, bool $respectServerHeaders = true) Start building a request with caching enabled.
 * @method static Request cacheWithKey(string $cacheKey, int $ttlSeconds = 3600, bool $respectServerHeaders = true) Start building a request with custom cache key.
 * @method static Request cacheWith(CacheConfig $config) Start building a request with custom cache configuration.
 * @method static Request timeout(int $seconds) Start building a request with timeout.
 * @method static Request connectTimeout(int $seconds) Start building a request with connection timeout.
 * @method static Request headers(array<string, string> $headers) Start building a request with headers.
 * @method static Request header(string $name, string $value) Start building a request with a single header.
 * @method static Request contentType(string $type) Start building a request with Content-Type header.
 * @method static Request accept(string $type) Start building a request with Accept header.
 * @method static Request asJson() Start building a request with Content-Type: application/json.
 * @method static Request asForm() Start building a request with Content-Type: application/x-www-form-urlencoded.
 * @method static Request withToken(string $token) Start building a request with bearer token.
 * @method static Request withBasicAuth(string $username, string $password) Start building a request with basic auth.
 * @method static Request withDigestAuth(string $username, string $password) Start building a request with digest auth.
 * @method static Request withHeaders(array<string, string|string[]> $headers) Start building a request with multiple headers.
 * @method static Request retry(int $maxRetries = 3, float $baseDelay = 1.0, float $backoffMultiplier = 2.0) Start building a request with retry logic.
 * @method static Request retryWith(RetryConfig $config) Start building a request with custom retry configuration.
 * @method static Request noRetry() Start building a request with retries disabled.
 * @method static Request redirects(bool $follow = true, int $max = 5) Start building a request with redirect configuration.
 * @method static Request verifySSL(bool $verify = true) Start building a request with SSL verification configuration.
 * @method static Request withUserAgent(string $userAgent) Start building a request with custom User-Agent.
 * @method static Request body(string $content) Start building a request with string body.
 * @method static Request withJson(array<string, mixed> $data) Start building a request with JSON body.
 * @method static Request withForm(array<string, mixed> $data) Start building a request with form data.
 * @method static Request withMultipart(array<string, mixed> $data) Start building a request with multipart data.
 * @method static Request withFile(string $name, string|UploadedFileInterface|resource $file, ?string $filename = null, ?string $contentType = null) Start building a request with a file attachment.
 * @method static Request withFiles(array<string, mixed> $files) Start building a request with multiple file attachments.
 * @method static Request multipartWithFiles(array<string, mixed> $data = [], array<string, mixed> $files = []) Start building a request with multipart form data and files.
 *
 * Cookie management methods:
 * @method static Request withCookie(string $name, string $value) Start building a request with a single cookie.
 * @method static Request withCookies(array<string, string> $cookies) Start building a request with multiple cookies.
 * @method static Request withCookieJar() Start building a request with an in-memory cookie jar.
 * @method static Request withFileCookieJar(string $filename, bool $includeSessionCookies = false) Start building a request with a file-based cookie jar.
 * @method static Request useCookieJar(CookieJarInterface $cookieJar) Start building a request with a custom cookie jar.
 * @method static Request withAllCookiesSaved(string $filename) Start building a request with all cookies saved to file.
 * @method static Request clearCookies() Start building a request with cookies cleared.
 * @method static Request cookieWithAttributes(string $name, string $value, array<string, mixed> $attributes = []) Start building a request with a cookie with additional attributes.
 *
 * Proxy configuration methods:
 * @method static Request withProxy(string $host, int $port, ?string $username = null, ?string $password = null) Start building a request with HTTP proxy configuration.
 * @method static Request withSocks4Proxy(string $host, int $port, ?string $username = null) Start building a request with SOCKS4 proxy configuration.
 * @method static Request withSocks5Proxy(string $host, int $port, ?string $username = null, ?string $password = null) Start building a request with SOCKS5 proxy configuration.
 * @method static Request proxyWith(ProxyConfig $config) Start building a request with custom proxy configuration.
 * @method static Request noProxy() Start building a request with proxy disabled.
 *
 * HTTP version negotiation methods:
 * @method static Request httpVersion(string $version) Start building a request with specific HTTP version.
 * @method static Request http1() Start building a request with HTTP/1.1 protocol version.
 * @method static Request http2() Start building a request with HTTP/2 negotiation.
 * @method static Request http3() Start building a request with HTTP/3 negotiation.
 *
 * Interceptor methods:
 * @method static Request interceptRequest(callable $callback) Start building a request with a request interceptor.
 * @method static Request interceptResponse(callable $callback) Start building a request with a response interceptor.
 *
 * SSE (Server-Sent Events) methods:
 * @method static CancellablePromiseInterface<SSEResponse> sse(string $url, ?callable $onEvent = null, ?callable $onError = null, ?SSEReconnectConfig $reconnectConfig = null) Start an SSE connection.
 * @method static Request sseDataFormat(string $format = 'json') Start building a request with SSE data format configuration.
 * @method static Request sseMap(callable $mapper) Start building a request with custom SSE event mapper.
 * @method static Request sseReconnect(bool $enabled = true, int $maxAttempts = 10, float $initialDelay = 1.0, float $maxDelay = 30.0, float $backoffMultiplier = 2.0, bool $jitter = true, list<string> $retryableErrors = [], ?callable $onReconnect = null, ?callable $shouldReconnect = null) Start building a request with SSE reconnection configuration.
 * @method static Request sseReconnectWith(SSEReconnectConfig $config) Start building a request with custom SSE reconnection configuration.
 * @method static Request noSseReconnect() Start building a request with SSE reconnection disabled.
 *
 * Advanced cURL methods:
 * @method static Request withCurlOption(int $option, mixed $value) Start building a request with a raw cURL option.
 * @method static Request withCurlOptions(array<int, mixed> $options) Start building a request with multiple raw cURL options.
 *
 * PSR-7 Message interface methods (immutable with* methods):
 * @method static Request withProtocolVersion(string $version) Return an instance with the specified HTTP protocol version.
 * @method static array<string, string[]> getHeaders() Retrieves all message header values.
 * @method static bool hasHeader(string $name) Checks if a header exists by the given case-insensitive name.
 * @method static string[] getHeader(string $name) Retrieves a message header value by the given case-insensitive name.
 * @method static string getHeaderLine(string $name) Retrieves a comma-separated string of the values for a single header.
 * @method static Request withHeader(string $name, string|string[] $value) Return an instance with the provided value replacing the specified header.
 * @method static Request withAddedHeader(string $name, string|string[] $value) Return an instance with the specified header appended with the given value.
 * @method static Request withoutHeader(string $name) Return an instance without the specified header.
 * @method static Stream getBody() Gets the body of the message.
 * @method static Request withBody(Stream $body) Return an instance with the specified message body.
 * @method static string getProtocolVersion() Retrieves the HTTP protocol version as a string.
 *
 * PSR-7 Request interface methods:
 * @method static string getRequestTarget() Retrieves the message's request target.
 * @method static Request withRequestTarget(string $requestTarget) Return an instance with the specific request-target.
 * @method static string getMethod() Retrieves the HTTP method of the request.
 * @method static Request withMethod(string $method) Return an instance with the provided HTTP method.
 * @method static Uri getUri() Retrieves the URI instance.
 * @method static Request withUri(Uri $uri, bool $preserveHost = false) Returns an instance with the provided URI.
 *
 * Request streaming methods:
 * @method static CancellablePromiseInterface<StreamingResponse> streamPost(string $url, mixed $body = null, ?callable $onChunk = null) Streams the response body of a POST request.
 *
 * Request execution methods:
 * @method static PromiseInterface<Response> send(string $method, string $url) Dispatches the configured request.
 *
 * Testing assertion methods (only available in testing mode):
 * 
 * Header assertions:
 * @method static void assertHeaderSent(string $name, ?string $expectedValue = null, ?int $requestIndex = null) Assert that a specific header was sent.
 * @method static void assertHeaderNotSent(string $name, ?int $requestIndex = null) Assert that a header was NOT sent.
 * @method static void assertHeadersSent(array<string, string> $expectedHeaders, ?int $requestIndex = null) Assert multiple headers were sent.
 * @method static void assertHeaderMatches(string $name, string $pattern, ?int $requestIndex = null) Assert header matches a pattern.
 * @method static void assertBearerTokenSent(string $expectedToken, ?int $requestIndex = null) Assert Bearer token was sent.
 * @method static void assertContentType(string $expectedType, ?int $requestIndex = null) Assert Content-Type header.
 * @method static void assertAcceptHeader(string $expectedType, ?int $requestIndex = null) Assert Accept header.
 * @method static void assertUserAgent(string $expectedUserAgent, ?int $requestIndex = null) Assert User-Agent header.
 * 
 * Request assertions:
 * @method static void assertRequestMade(string $method, string $url, array<string, mixed> $options = []) Assert a request was made.
 * @method static void assertNoRequestsMade() Assert no requests were made.
 * @method static void assertRequestCount(int $expected) Assert request count.
 * @method static void assertRequestMatchingUrl(string $method, string $pattern) Assert that a request was made with a specific URL pattern.
 * @method static void assertRequestSequence(array<array{method: string, url: string}> $expectedSequence) Assert that requests were made in a specific order.
 * @method static void assertRequestAtIndex(string $method, string $url, int $index) Assert that a request was made at a specific index.
 * @method static void assertSingleRequestTo(string $url) Assert that exactly one request was made to a URL.
 * @method static void assertRequestNotMade(string $method, string $url) Assert that a request was NOT made.
 * @method static void assertRequestCountTo(string $url, int $maxCount) Assert that requests to a URL do not exceed a limit.
 * 
 * Request body assertions:
 * @method static void assertRequestWithBody(string $method, string $url, string $expectedBody) Assert that a request was made with specific body content.
 * @method static void assertRequestBodyContains(string $method, string $url, string $needle) Assert that a request was made with body containing a string.
 * @method static void assertRequestWithJson(string $method, string $url, array<mixed> $expectedJson) Assert that a request was made with JSON body.
 * @method static void assertRequestJsonContains(string $method, string $url, array<mixed> $expectedKeys) Assert that a request was made with JSON containing specific keys.
 * @method static void assertRequestJsonPath(string $method, string $url, string $path, mixed $expectedValue) Assert that a request was made with a JSON path value.
 * @method static void assertRequestWithEmptyBody(string $method, string $url) Assert that a request was made with empty body.
 * @method static void assertRequestHasBody(string $method, string $url) Assert that a request has a non-empty body.
 * @method static void assertRequestIsJson(string $method, string $url) Assert that a request was made with JSON body.
 * @method static void assertRequestBodyMatches(string $method, string $url, string $pattern) Assert that a request body matches a pattern.
 * 
 * Cookie assertions:
 * @method static void assertCookieSent(string $name) Assert a cookie was sent.
 * @method static void assertCookieExists(string $name) Assert a cookie exists in jar.
 * @method static void assertCookieValue(string $name, string $expectedValue) Assert cookie value.
 * 
 * Download assertions:
 * @method static void assertDownloadMade(string $url, string $destination) Assert that a download was made to a specific destination.
 * @method static void assertDownloadMadeToUrl(string $url) Assert that a download was made to any destination.
 * @method static void assertFileDownloaded(string $destination) Assert that a specific file was downloaded.
 * @method static void assertDownloadWithHeaders(string $url, array<string, string> $expectedHeaders) Assert that a download was made with specific headers.
 * @method static void assertNoDownloadsMade() Assert that no downloads were made.
 * @method static void assertDownloadCount(int $expected) Assert a specific number of downloads were made.
 * @method static void assertDownloadedFileExists(string $destination) Assert that a file exists at the download destination.
 * @method static void assertDownloadedFileContains(string $destination, string $expectedContent) Assert that a downloaded file has specific content.
 * @method static void assertDownloadedFileContainsString(string $destination, string $needle) Assert that a downloaded file contains a substring.
 * @method static void assertDownloadedFileSize(string $destination, int $expectedSize) Assert that a downloaded file size matches expected size.
 * @method static void assertDownloadedFileSizeBetween(string $destination, int $minSize, int $maxSize) Assert that a downloaded file size is within a range.
 * @method static void assertDownloadWithMethod(string $url, string $method) Assert that a download was made using a specific HTTP method.
 * 
 * Stream assertions:
 * @method static void assertStreamMade(string $url) Assert that a streaming request was made.
 * @method static void assertStreamWithCallback(string $url) Assert that a streaming request was made with a chunk callback.
 * @method static void assertStreamWithHeaders(string $url, array<string, string> $expectedHeaders) Assert that a streaming request was made with specific headers.
 * @method static void assertStreamWithMethod(string $url, string $method) Assert that a streaming request was made using a specific HTTP method.
 * @method static void assertNoStreamsMade() Assert that no streaming requests were made.
 * @method static void assertStreamCount(int $expected) Assert a specific number of streaming requests were made.
 * 
 * SSE assertions:
 * @method static void assertSSEConnectionMade(string $url) Assert that an SSE connection was made to the specified URL.
 * @method static void assertNoSSEConnections() Assert that no SSE connections were made.
 * @method static void assertSSELastEventId(string $expectedId, ?int $requestIndex = null) Assert that the Last-Event-ID header matches the expected value.
 * @method static void assertSSEConnectionAttempts(string $url, int $expectedAttempts) Assert that SSE connection was attempted a specific number of times.
 * @method static void assertSSEConnectionAttemptsAtLeast(string $url, int $minAttempts) Assert that SSE connection was attempted at least a minimum number of times.
 * @method static void assertSSEConnectionAttemptsAtMost(string $url, int $maxAttempts) Assert that SSE connection was attempted at most a maximum number of times.
 * @method static void assertSSEReconnectionOccurred(string $url) Assert that SSE reconnection occurred with Last-Event-ID header.
 * @method static void assertSSEConnectionHasHeader(string $url, string $headerName, string $expectedValue) Assert that SSE connection has specific header value.
 * @method static void assertSSEConnectionMissingHeader(string $url, string $headerName) Assert that SSE connection does not have a specific header.
 * @method static void assertSSEConnectionsMadeToMultipleUrls(array<string> $urls) Assert that multiple SSE connections were made to different URLs.
 * @method static void assertSSEConnectionsInOrder(array<string> $urls) Assert that SSE connections were made in a specific order.
 * @method static void assertSSEConnectionAuthenticated(string $url, ?string $expectedToken = null) Assert that SSE connection includes authentication header.
 * @method static void assertSSEReconnectionProgression(string $url) Assert that SSE reconnection attempts have increasing Last-Event-IDs.
 * @method static void assertFirstSSEConnectionHasNoLastEventId(string $url) Assert that the first SSE connection has no Last-Event-ID header.
 * @method static void assertSSEConnectionRequestedWithProperHeaders(string $url) Assert that SSE connection was requested with proper Cache-Control headers.
 * @method static void assertSSEConnectionCount(string $url, int $expectedCount) Assert that SSE connection count matches expected for a URL pattern.
 * 
 * Testing helper methods:
 * @method static array<int, RecordedRequest> getSSEConnectionAttempts(string $url) Get all SSE connection attempts for a specific URL.
 * @method static RecordedRequest|null getLastRequest() Get the last recorded request.
 * @method static RecordedRequest|null getRequest(int $index) Get a specific request by index.
 * @method static list<RecordedRequest> getRequestHistory() Get all recorded requests.
 * @method static array<int, RecordedRequest> getRequestsTo(string $url) Get all requests to a specific URL.
 * @method static array<int, RecordedRequest> getRequestsByMethod(string $method) Get all requests using a specific method.
 * @method static array<int, RecordedRequest> getDownloadRequests() Get all download requests from history.
 * @method static RecordedRequest|null getLastDownload() Get the last download request.
 * @method static RecordedRequest|null getFirstDownload() Get the first download request.
 * @method static string|null getDownloadDestination(string $url) Get download destination for a specific URL.
 * @method static array<int, RecordedRequest> getStreamRequests() Get all streaming requests from history.
 * @method static RecordedRequest|null getLastStream() Get the last streaming request.
 * @method static RecordedRequest|null getFirstStream() Get the first streaming request.
 * @method static bool streamHasCallback(RecordedRequest $request) Check if a stream request has a callback.
 * @method static void dumpLastRequest() Dump the last request for debugging.
 * @method static void dumpRequestsByMethod(string $method) Dump all requests with a specific method.
 * @method static void dumpDownloads() Dump information about all downloads for debugging.
 * @method static void dumpLastDownload() Dump detailed information about the last download.
 * @method static void dumpStreams() Dump information about all streams for debugging.
 * @method static void dumpLastStream() Dump detailed information about the last stream.
 */
class Http
{
    /**
     * @var HttpHandler|null Singleton instance of the core HTTP handler.
     */
    private static ?HttpHandler $instance = null;

    /**
     * @var TestingHttpHandler|null Testing handler instance when in testing mode.
     */
    private static ?TestingHttpHandler $testingInstance = null;

    /**
     *  @var bool Flag to track if we're in testing mode.
     */
    private static bool $isTesting = false;

    /**
     * Lazily initializes and returns the singleton HttpHandler instance.
     */
    private static function getInstance(): HttpHandler
    {
        if (self::$isTesting && self::$testingInstance !== null) {
            return self::$testingInstance;
        }

        if (self::$instance === null) {
            self::$instance = new HttpHandler();
        }

        return self::$instance;
    }

    /**
     * Creates a new fluent HTTP request builder.
     *
     * @return Request The request builder instance.
     */
    public static function request(): Request
    {
        return self::getInstance()->request();
    }

    /**
     * Enable testing mode with a clean TestingHttpHandler instance.
     *
     * This method switches the Http client to use a TestingHttpHandler instead
     * of the regular HttpHandler, allowing you to mock requests and responses
     * for testing purposes.
     *
     * @return TestingHttpHandler The testing handler for configuration
     */
    public static function startTesting(): TestingHttpHandler
    {
        self::$isTesting = true;

        if (self::$testingInstance === null) {
            self::$testingInstance = new TestingHttpHandler();
        }

        return self::$testingInstance;
    }

    /**
     * Get the current testing handler instance.
     *
     * @return TestingHttpHandler The testing handler
     *
     * @throws \RuntimeException If not in testing mode
     */
    public static function getTestingHandler(): TestingHttpHandler
    {
        if (! self::$isTesting || self::$testingInstance === null) {
            throw new \RuntimeException('Not in testing mode. Call Http::startTesting() first.');
        }

        return self::$testingInstance;
    }

    /**
     * Convenience method to quickly mock a request in testing mode.
     * Follows Laravel's Http::fake() pattern.
     *
     * @param  string  $method  HTTP method to mock (default: '*' for any)
     *
     * @throws \RuntimeException If not in testing mode
     */
    public static function mock(string $method = '*'): MockRequestBuilder
    {
        if (! self::$isTesting || self::$testingInstance === null) {
            throw new \RuntimeException('Not in testing mode. Call Http::startTesting() first.');
        }

        return self::$testingInstance->mock($method);
    }

    /**
     * Disable testing mode and return to normal HTTP operations.
     *
     * This restores the Http client to use the regular HttpHandler,
     * clearing all mocked requests and testing state.
     */
    public static function stopTesting(): void
    {
        self::$isTesting = false;
        self::$testingInstance = null;
    }

    /**
     * Resets the singleton instance. Useful for testing environments.
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$testingInstance = null;
        self::$isTesting = false;
    }

    /**
     * Allows setting a custom HttpHandler instance, primarily for mocking during tests.
     *
     * @param  HttpHandler  $handler  The custom handler instance.
     */
    public static function setInstance(HttpHandler $handler): void
    {
        if ($handler instanceof TestingHttpHandler) {
            self::$isTesting = true;
            self::$testingInstance = $handler;
        } else {
            self::$isTesting = false;
            self::$instance = $handler;
        }
    }

    /**
     * Magic method to handle dynamic static calls.
     *
     * All calls are delegated to a fresh Request instance, providing a clean
     * fluent interface that starts from the Http facade.
     *
     * @param  string  $method  The method name.
     * @param  array<mixed>  $arguments  The arguments to pass to the method.
     * @return mixed The result of the proxied method call.
     */
    public static function __callStatic(string $method, array $arguments)
    {
        /** @var list<string> */
        $assertionMethods = [
            // Header assertions
            'assertHeaderSent',
            'assertHeaderNotSent',
            'assertHeadersSent',
            'assertHeaderMatches',
            'assertBearerTokenSent',
            'assertContentType',
            'assertAcceptHeader',
            'assertUserAgent',
            
            // Request assertions
            'assertRequestMade',
            'assertNoRequestsMade',
            'assertRequestCount',
            'assertRequestMatchingUrl',
            'assertRequestSequence',
            'assertRequestAtIndex',
            'assertSingleRequestTo',
            'assertRequestNotMade',
            'assertRequestCountTo',
            
            // Request body assertions
            'assertRequestWithBody',
            'assertRequestBodyContains',
            'assertRequestWithJson',
            'assertRequestJsonContains',
            'assertRequestJsonPath',
            'assertRequestWithEmptyBody',
            'assertRequestHasBody',
            'assertRequestIsJson',
            'assertRequestBodyMatches',
            
            // Cookie assertions
            'assertCookieSent',
            'assertCookieExists',
            'assertCookieValue',
            
            // Download assertions
            'assertDownloadMade',
            'assertDownloadMadeToUrl',
            'assertFileDownloaded',
            'assertDownloadWithHeaders',
            'assertNoDownloadsMade',
            'assertDownloadCount',
            'assertDownloadedFileExists',
            'assertDownloadedFileContains',
            'assertDownloadedFileContainsString',
            'assertDownloadedFileSize',
            'assertDownloadedFileSizeBetween',
            'assertDownloadWithMethod',
            
            // Stream assertions
            'assertStreamMade',
            'assertStreamWithCallback',
            'assertStreamWithHeaders',
            'assertStreamWithMethod',
            'assertNoStreamsMade',
            'assertStreamCount',
            
            // SSE assertions
            'assertSSEConnectionMade',
            'assertNoSSEConnections',
            'assertSSELastEventId',
            'assertSSEConnectionAttempts',
            'assertSSEConnectionAttemptsAtLeast',
            'assertSSEConnectionAttemptsAtMost',
            'assertSSEReconnectionOccurred',
            'assertSSEConnectionHasHeader',
            'assertSSEConnectionMissingHeader',
            'assertSSEConnectionsMadeToMultipleUrls',
            'assertSSEConnectionsInOrder',
            'assertSSEConnectionAuthenticated',
            'assertSSEReconnectionProgression',
            'assertFirstSSEConnectionHasNoLastEventId',
            'assertSSEConnectionRequestedWithProperHeaders',
            'assertSSEConnectionCount',
            
            // Testing helper methods
            'getSSEConnectionAttempts',
            'getLastRequest',
            'getRequest',
            'getRequestHistory',
            'getRequestsTo',
            'getRequestsByMethod',
            'getDownloadRequests',
            'getLastDownload',
            'getFirstDownload',
            'getDownloadDestination',
            'getStreamRequests',
            'getLastStream',
            'getFirstStream',
            'streamHasCallback',
            'dumpLastRequest',
            'dumpRequestsByMethod',
            'dumpDownloads',
            'dumpLastDownload',
            'dumpStreams',
            'dumpLastStream',
        ];

        if (in_array($method, $assertionMethods, true)) {
            if (! self::$isTesting || self::$testingInstance === null) {
                throw new \RuntimeException(
                    "Cannot call assertion method '{$method}' outside of testing mode. " .
                    'Call Http::startTesting() first.'
                );
            }

            /** @phpstan-ignore-next-line */
            return self::$testingInstance->{$method}(...$arguments);
        }

        /** @var list<string> */
        $directMethods = ['fetch'];

        if (in_array($method, $directMethods, true)) {
            /** @phpstan-ignore-next-line */
            return self::getInstance()->{$method}(...$arguments);
        }

        $request = self::request();

        if (method_exists($request, $method)) {
            /** @phpstan-ignore-next-line */
            return $request->{$method}(...$arguments);
        }

        throw new \BadMethodCallException("Method {$method} does not exist on " . static::class);
    }
}