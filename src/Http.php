<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

use Hibla\HttpClient\Handlers\HttpHandler;
use Hibla\HttpClient\Interfaces\Cookie\CookieJarInterface;
use Hibla\HttpClient\Interfaces\HttpClientInterface;
use Hibla\HttpClient\Interfaces\ResponseInterface;
use Hibla\HttpClient\Interfaces\SSE\SSEBuilderInterface;
use Hibla\HttpClient\Interfaces\StreamingResponseInterface;
use Hibla\HttpClient\Testing\MockRequestBuilder;
use Hibla\HttpClient\Testing\TestingHttpHandler;
use Hibla\HttpClient\Testing\Utilities\RecordedRequest;
use Hibla\HttpClient\ValueObjects\RetryConfig;
use Hibla\Promise\Interfaces\PromiseInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A static API for clean, expressive, and asynchronous HTTP operations.
 *
 * This class provides a simple, static entry point for all HTTP-related tasks,
 * including GET, POST, streaming, and file downloads. It abstracts away the
 * underlying handler and event loop management for a more convenient API.
 *
 * All requests — whether made through the fluent builder, direct HTTP methods,
 * or fetch() — share the same execution path through HttpClient and its
 * interceptor pipeline. There is no global configuration or global interceptor
 * registry; all configuration is per-request via the fluent builder, or
 * shared by extracting a pre-configured HttpClientInterface instance.
 *
 * ## Basic usage
 *
 * ```php
 * // Direct methods
 * $response = await Http::get('https://api.example.com/users');
 * $response = await Http::post('https://api.example.com/users', ['name' => 'Alice']);
 *
 * // Fluent builder
 * $response = await Http::request()
 *     ->withToken($token)
 *     ->withUserAgent('MyApp/1.0')
 *     ->timeout(15)
 *     ->get('https://api.example.com/users');
 *
 * // Fetch-style
 * $response = await Http::fetch('https://api.example.com/users', [
 *     'method'  => 'POST',
 *     'json'    => ['name' => 'Alice'],
 *     'timeout' => 15,
 * ]);
 * ```
 *
 * ## Sharing configuration across requests
 *
 * Rather than using global interceptors or global configuration, extract a
 * pre-configured client instance and reuse it. Because HttpClient is immutable,
 * the base instance is never mutated by individual requests:
 *
 * ```php
 * $client = Http::request()
 *     ->withToken($token)
 *     ->withUserAgent('MyApp/1.0')
 *     ->intercept($loggingMiddleware)
 *     ->timeout(30);
 *
 * // Each call returns a new clone — $client stays clean
 * $client->get('https://api.example.com/users');
 * $client->post('https://api.example.com/orders', $data);
 * ```
 *
 * Direct HTTP methods:
 *
 * @method static PromiseInterface<ResponseInterface> get(string $url, array<string, mixed> $query = []) Performs a GET request.
 * @method static PromiseInterface<ResponseInterface> post(string $url, array<string, mixed> $data = []) Performs a POST request.
 * @method static PromiseInterface<ResponseInterface> put(string $url, array<string, mixed> $data = []) Performs a PUT request.
 * @method static PromiseInterface<ResponseInterface> delete(string $url) Performs a DELETE request.
 * @method static PromiseInterface<ResponseInterface> patch(string $url, array<string, mixed> $data = []) Performs a PATCH request.
 * @method static PromiseInterface<ResponseInterface> options(string $url) Performs an OPTIONS request.
 * @method static PromiseInterface<ResponseInterface> head(string $url) Performs a HEAD request.
 * @method static PromiseInterface<StreamingResponseInterface> stream(string $url, ?callable $onChunk = null) Streams a response body.
 * @method static PromiseInterface<StreamingResponseInterface> streamPost(string $url, mixed $body = null, ?callable $onChunk = null) Streams the response body of a POST request.
 * @method static PromiseInterface<array{file: string, status: int, headers: array<mixed>, protocol_version: string|null, size: int|false}> download(string $url, string $destination, ?callable $onProgress = null) Downloads a file to the given destination path, with an optional progress callback.
 * @method static PromiseInterface<ResponseInterface> send(string $method, string $url) Dispatches the configured request.
 * @method static PromiseInterface<array{url: string, status: int, headers: array<mixed>, protocol_version: string|null}> upload(string $url, string $source, ?callable $onProgress = null) Uploads a local file to the given URL using a non-buffered chunked read, with an optional progress callback.
 * @method static SSEBuilderInterface sse(string $url) Create a fluent SSE connection builder.
 *
 * Header configuration methods (ConfiguresHeadersInterface):
 * @method static HttpClientInterface contentType(string $type) Start building a request with Content-Type header.
 * @method static HttpClientInterface accept(string $type) Start building a request with Accept header.
 * @method static HttpClientInterface asJson() Start building a request with Content-Type: application/json.
 * @method static HttpClientInterface asForm() Start building a request with Content-Type: application/x-www-form-urlencoded.
 * @method static HttpClientInterface withUserAgent(string $userAgent) Start building a request with custom User-Agent.
 * @method static HttpClientInterface withHeaders(array<string, string|string[]> $headers) Start building a request with multiple headers.
 * @method static HttpClientInterface withHeader(string $name, string|string[] $value) Start building a request with a single header.
 * @method static HttpClientInterface withAddedHeader(string $name, string|string[] $value) Return an instance with the specified header appended with the given value.
 * @method static HttpClientInterface withoutHeader(string $name) Start building a request without the specified header.
 *
 * Auth configuration methods (ConfiguresAuthInterface):
 * @method static HttpClientInterface withToken(string $token, string $type = 'Bearer') Start building a request with a token.
 * @method static HttpClientInterface withBasicAuth(string $username, string $password) Start building a request with basic auth.
 * @method static HttpClientInterface withDigestAuth(string $username, string $password) Start building a request with digest auth.
 *
 * Body configuration methods (ConfiguresBodyInterface):
 * @method static HttpClientInterface body(string $content) Start building a request with string body.
 * @method static HttpClientInterface withJson(array<string, mixed> $data) Start building a request with JSON body.
 * @method static HttpClientInterface withForm(array<string, mixed> $data) Start building a request with form data.
 * @method static HttpClientInterface withMultipart(array<string, mixed> $data) Start building a request with multipart data.
 *
 * Cookie management methods (ConfiguresCookiesInterface):
 * @method static HttpClientInterface withCookie(string $name, string $value) Start building a request with a single cookie.
 * @method static HttpClientInterface withCookies(array<string, string> $cookies) Start building a request with multiple cookies.
 * @method static HttpClientInterface withCookieJar() Start building a request with an in-memory cookie jar.
 * @method static HttpClientInterface useCookieJar(CookieJarInterface $cookieJar) Start building a request with a custom cookie jar.
 * @method static HttpClientInterface clearCookies() Start building a request with cookies cleared.
 * @method static HttpClientInterface cookieWithAttributes(string $name, string $value, array<string, mixed> $attributes = []) Start building a request with a cookie with additional attributes.
 * @method static CookieJarInterface|null getCookieJar() Return the currently active cookie jar, or null if none is configured.
 *
 * Transport configuration methods (ConfiguresTransportInterface):
 * @method static HttpClientInterface timeout(int $seconds) Start building a request with timeout.
 * @method static HttpClientInterface connectTimeout(int $seconds) Start building a request with connection timeout.
 * @method static HttpClientInterface redirects(bool $follow = true, int $max = 5) Start building a request with redirect configuration.
 * @method static HttpClientInterface verifySSL(bool $verify = true) Start building a request with SSL verification configuration.
 * @method static HttpClientInterface httpVersion(string $version) Start building a request with specific HTTP version.
 * @method static HttpClientInterface http1() Start building a request with HTTP/1.1 protocol version.
 * @method static HttpClientInterface http2() Start building a request with HTTP/2 negotiation.
 * @method static HttpClientInterface http3() Start building a request with HTTP/3 negotiation.
 *
 * Retry configuration methods (ConfiguresRetryInterface):
 * @method static HttpClientInterface retry(int $maxRetries = 3, float $baseDelay = 1.0, float $backoffMultiplier = 2.0) Start building a request with retry logic.
 * @method static HttpClientInterface retryWith(RetryConfig $config) Start building a request with custom retry configuration.
 * @method static HttpClientInterface noRetry() Start building a request with retries disabled.
 *
 * Proxy configuration methods (ConfiguresProxyInterface):
 * @method static HttpClientInterface withProxy(string $host, int $port, ?string $username = null, ?string $password = null) Start building a request with HTTP proxy configuration.
 * @method static HttpClientInterface withSocks4Proxy(string $host, int $port, ?string $username = null) Start building a request with SOCKS4 proxy configuration.
 * @method static HttpClientInterface withSocks5Proxy(string $host, int $port, ?string $username = null, ?string $password = null) Start building a request with SOCKS5 proxy configuration.
 * @method static HttpClientInterface proxyWith(ProxyConfig $config) Start building a request with custom proxy configuration.
 * @method static HttpClientInterface noProxy() Start building a request with proxy disabled.
 *
 * File attachment methods (ConfiguresFilesInterface):
 * @method static HttpClientInterface withFile(string $name, string|UploadedFileInterface|resource $file, ?string $filename = null, ?string $contentType = null) Start building a request with a file attachment.
 * @method static HttpClientInterface withFiles(array<string, mixed> $files) Start building a request with multiple file attachments.
 * @method static HttpClientInterface multipartWithFiles(array<string, mixed> $data = [], array<string, mixed> $files = []) Start building a request with multipart form data and files.
 *
 * URL template methods (ConfiguresUrlInterface):
 * @method static HttpClientInterface withUrlParameter(string $name, mixed $value) Set a single URL parameter for URI template substitution.
 * @method static HttpClientInterface withUrlParameters(array<string, mixed> $parameters) Set multiple URL parameters for URI template substitution.
 *
 * Interceptor methods (HttpInterceptorInterface):
 * @method static HttpClientInterface intercept(callable(RequestInterface, callable): PromiseInterface<ResponseInterface> $middleware) Add a full pipeline interceptor.
 * @method static HttpClientInterface interceptRequest(callable(RequestInterface): (RequestInterface|PromiseInterface<RequestInterface>) $callback) Start building a request with a request interceptor.
 * @method static HttpClientInterface interceptResponse(callable(ResponseInterface): (ResponseInterface|PromiseInterface<ResponseInterface>) $callback) Start building a request with a response interceptor.
 *
 * cURL escape-hatch methods (ConfiguresCurlInterface):
 * @method static HttpClientInterface withCurlOption(int $option, mixed $value) Start building a request with a raw cURL option.
 * @method static HttpClientInterface withCurlOptions(array<int, mixed> $options) Start building a request with multiple raw cURL options.
 *
 * PSR-7 Message interface methods:
 * @method static string getProtocolVersion() Retrieves the HTTP protocol version as a string.
 * @method static HttpClientInterface withProtocolVersion(string $version) Return an instance with the specified HTTP protocol version.
 * @method static array<string, string[]> getHeaders() Retrieves all message header values.
 * @method static bool hasHeader(string $name) Checks if a header exists by the given case-insensitive name.
 * @method static string[] getHeader(string $name) Retrieves a message header value by the given case-insensitive name.
 * @method static string getHeaderLine(string $name) Retrieves a comma-separated string of the values for a single header.
 * @method static Stream getBody() Gets the body of the message.
 * @method static HttpClientInterface withBody(Stream $body) Return an instance with the specified message body.
 *
 * PSR-7 Request interface methods:
 * @method static string getRequestTarget() Retrieves the message's request target.
 * @method static HttpClientInterface withRequestTarget(string $requestTarget) Return an instance with the specific request-target.
 * @method static string getMethod() Retrieves the HTTP method of the request.
 * @method static HttpClientInterface withMethod(string $method) Return an instance with the provided HTTP method.
 * @method static Uri getUri() Retrieves the URI instance.
 * @method static HttpClientInterface withUri(Uri $uri, bool $preserveHost = false) Returns an instance with the provided URI.
 *
 * Xml methods:
 * @method static HttpClientInterface asXml() Start building a request with Content-Type: application/xml.
 * @method static HttpClientInterface withXml(string|\SimpleXMLElement $xml) Start building a request with XML body.
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
 * Upload assertions:
 * @method static void assertUploadMade(string $url, string $source) Assert that an upload was made to a specific destination.
 * @method static void assertUploadMadeToUrl(string $url) Assert that an upload was made to any destination.
 * @method static void assertNoUploadsMade() Assert that no uploads were made.
 * @method static void assertUploadCount(int $expected) Assert a specific number of uploads were made.
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
 * @method static array<int, RecordedRequest> getUploadRequests() Get all upload requests from history.
 * @method static RecordedRequest|null getLastUpload() Get the last upload request.
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
     * Testing handler instance injected into every HttpClient when in testing mode.
     *
     * Null means production mode — each HttpClient creates its own handler lazily.
     * Non-null means testing mode — requests are intercepted and recorded.
     *
     * This is the single source of truth for whether testing mode is active.
     * A separate boolean flag is intentionally absent: the nullable instance
     * already expresses "active or not" without the risk of the two values
     * diverging.
     */
    private static ?TestingHttpHandler $testingInstance = null;

    /**
     * Creates a new fluent HTTP client builder.
     *
     * In production mode: returns a fresh HttpClient with no shared state.
     * Each client lazily creates its own HttpHandler on first dispatch.
     *
     * In testing mode: injects the TestingHttpHandler so all requests made
     * through this client are intercepted, recorded, and matchable against
     * mocked responses. Application code requires no changes between modes.
     */
    public static function request(): HttpClientInterface
    {
        $client = new HttpClient();

        if (self::$testingInstance !== null) {
            $client = $client->setHandler(self::$testingInstance);
        }

        return $client;
    }

    /**
     * Fetch-style entry point — translates a flat options array into fluent
     * builder calls then dispatches through the interceptor pipeline.
     *
     * All option mapping is handled by {@see FetchRequest}, keeping this
     * facade method as a thin delegation point. Because this calls
     * self::request() internally, testing mode is honoured automatically —
     * no special casing required.
     *
     * Supported options: method, headers, json, form, body, auth, timeout,
     * connect_timeout, follow_redirects, max_redirects, verify_ssl,
     * user_agent, http_version / protocol, retry, proxy, cookies,
     * cookie_jar, stream, on_chunk / onChunk, download / save_to,
     * sse, on_event / onEvent, on_error / onError, reconnect.
     * Integer keys are forwarded as raw cURL options.
     *
     * @param  string $url
     * @param  array<int|string, mixed>  $options
     * @return PromiseInterface<ResponseInterface>
     */
    public static function fetch(string $url, array $options = []): PromiseInterface
    {
        return (new FetchRequest())->send(self::request(), $url, $options);
    }

    /**
     * Enable testing mode.
     *
     * Switches the facade to use a TestingHttpHandler instead of the real
     * cURL-backed handler. All subsequent calls to request() and fetch()
     * will route through the testing handler until stopTesting() is called.
     *
     * Application code requires zero changes between production and testing —
     * the handler swap is invisible to callers.
     *
     * ```php
     * Http::startTesting();
     * Http::mock('GET')->url('https://api.example.com/*')->respondWith(200, [...]);
     *
     * $response = await Http::get('https://api.example.com/users'); // intercepted
     *
     * Http::assertRequestMade('GET', 'https://api.example.com/users');
     * Http::stopTesting();
     * ```
     *
     * @return TestingHttpHandler The testing handler for mock configuration.
     */
    public static function startTesting(): TestingHttpHandler
    {
        self::$testingInstance ??= new TestingHttpHandler();

        return self::$testingInstance;
    }

    /**
     * Return the active testing handler for advanced mock configuration.
     *
     * @return TestingHttpHandler
     *
     * @throws \RuntimeException If not in testing mode.
     */
    public static function getTestingHandler(): TestingHttpHandler
    {
        return self::$testingInstance
            ?? throw new \RuntimeException(
                'Not in testing mode. Call Http::startTesting() first.'
            );
    }

    /**
     * Convenience method to configure a mock in testing mode.
     *
     * Follows the same pattern as Laravel's Http::fake(). Delegates directly
     * to TestingHttpHandler::mock() for fluent mock configuration.
     *
     * @param  string  $method  HTTP method to mock, or '*' to match any method.
     *
     * @throws \RuntimeException If not in testing mode.
     */
    public static function mock(string $method = '*'): MockRequestBuilder
    {
        return self::getTestingHandler()->mock($method);
    }

    /**
     * Reset the current testing state without disabling testing mode.
     *
     * Clears all recorded requests, mocked responses, and resets any
     * network simulations, temporary files, or cookie state.
     *
     * Use this between test cases in a single test file to ensure each
     * test starts with a clean slate without having to call startTesting() again.
     */
    public static function resetTesting(): void
    {
        if (self::$testingInstance !== null) {
            self::$testingInstance->reset();
        }
    }

    /**
     * Disable testing mode and return to normal HTTP operations.
     *
     * Clears the testing handler and all recorded requests and mocked
     * responses. Subsequent calls to request() and fetch() will use real
     * HTTP execution again.
     *
     * Should be called in tearDown() after each test to prevent state
     * leaking between tests.
     */
    public static function stopTesting(): void
    {
        self::$testingInstance = null;
    }

    /**
     * Magic method to handle dynamic static calls.
     *
     * Routes calls in this order:
     *
     * 1. Assertion and testing-helper methods → TestingHttpHandler.
     *    Throws if called outside testing mode.
     *
     * 2. Everything else → a fresh HttpClient builder instance via request().
     *    This covers all fluent builder methods (withToken, timeout, etc.)
     *    as well as terminal methods (get, post, stream, download, sse, send).
     *
     * Note: fetch() is a real static method above and never reaches here.
     *
     * @param  string        $method     The method name.
     * @param  array<mixed>  $arguments  The arguments to pass to the method.
     * @return mixed The result of the proxied method call.
     *
     * @throws \RuntimeException      If an assertion method is called outside testing mode.
     * @throws \BadMethodCallException If the method does not exist on HttpClient.
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        /** @var list<string> $assertionMethods */
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

            // Upload assertions
            'assertUploadMade',
            'assertUploadMadeToUrl',
            'assertNoUploadsMade',
            'assertUploadCount',

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
            'getUploadRequests',
            'getLastUpload',
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

        if (\in_array($method, $assertionMethods, true)) {
            if (self::$testingInstance === null) {
                throw new \RuntimeException(
                    "Cannot call assertion method '{$method}' outside of testing mode. " .
                        'Call Http::startTesting() first.'
                );
            }

            /** @phpstan-ignore-next-line */
            return self::$testingInstance->{$method}(...$arguments);
        }

        // Delegate all fluent builder and terminal methods to a fresh
        // HttpClient instance. request() handles testing mode injection.
        $client = self::request();

        if (method_exists($client, $method)) {
            /** @phpstan-ignore-next-line */
            return $client->{$method}(...$arguments);
        }

        throw new \BadMethodCallException(
            "Method {$method} does not exist on " . static::class
        );
    }
}
