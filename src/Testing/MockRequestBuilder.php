<?php

namespace Hibla\Http\Testing;

use Hibla\Http\Testing\Utilities\CookieManager;

class MockRequestBuilder
{
    private TestingHttpHandler $handler;
    private MockedRequest $request;

    public function __construct(TestingHttpHandler $handler, string $method = '*')
    {
        $this->handler = $handler;
        $this->request = new MockedRequest($method);
    }

    /**
     * Set the URL pattern to match.
     *
     * @param  string  $pattern  URL pattern (supports wildcards)
     */
    public function url(string $pattern): self
    {
        $this->request->setUrlPattern($pattern);
        return $this;
    }

    /**
     * Expect a specific header in the request.
     *
     * @param  string  $name  Header name
     * @param  string  $value  Expected header value
     */
    public function expectHeader(string $name, string $value): self
    {
        $this->request->addHeaderMatcher($name, $value);
        return $this;
    }

    /**
     * Expect multiple headers in the request.
     *
     * @param  array<string, string>  $headers  Expected headers
     */
    public function expectHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->expectHeader($name, $value);
        }
        return $this;
    }

    /**
     * Expect a specific body pattern in the request.
     *
     * @param  string  $pattern  Body pattern (supports wildcards)
     */
    public function expectBody(string $pattern): self
    {
        $this->request->setBodyMatcher($pattern);
        return $this;
    }

    /**
     * Expect specific JSON data in the request body.
     *
     * @param  array  $data  Expected JSON data (must match exactly)
     */
    public function expectJson(array $data): self
    {
        $this->request->setJsonMatcher($data);
        return $this;
    }

    /**
     * Expect specific cookies to be present in the request.
     *
     * @param  array<string, string>  $expectedCookies  Cookie name => value pairs
     */
    public function expectCookies(array $expectedCookies): self
    {
        foreach ($expectedCookies as $name => $value) {
            $this->request->addHeaderMatcher('cookie', $name.'='.$value);
        }
        return $this;
    }

    /**
     * Set the HTTP status code for the response.
     *
     * @param  int  $status  HTTP status code (default: 200)
     */
    public function respondWithStatus(int $status = 200): self
    {
        $this->request->setStatusCode($status);
        return $this;
    }

    /**
     * Shorthand for respondWithStatus().
     */
    public function status(int $status): self
    {
        return $this->respondWithStatus($status);
    }

    /**
     * Set the response body as a string.
     *
     * @param  string  $body  Response body content
     */
    public function respondWith(string $body): self
    {
        $this->request->setBody($body);
        return $this;
    }

    /**
     * Set the response body as JSON.
     * Automatically sets Content-Type: application/json header.
     *
     * @param  array  $data  Data to JSON-encode
     */
    public function respondJson(array $data): self
    {
        $this->request->setBody(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $this->request->addResponseHeader('Content-Type', 'application/json');
        return $this;
    }

    /**
     * Add a response header.
     *
     * @param  string  $name  Header name
     * @param  string  $value  Header value
     */
    public function respondWithHeader(string $name, string $value): self
    {
        $this->request->addResponseHeader($name, $value);
        return $this;
    }

    /**
     * Add multiple response headers.
     *
     * @param  array<string, string>  $headers  Headers to add
     */
    public function respondWithHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->respondWithHeader($name, $value);
        }
        return $this;
    }

    /**
     * Set a sequence of body chunks to simulate streaming.
     * This will cause the onChunk callback to be fired for each chunk.
     *
     * @param  array<string>  $chunks  Body chunks
     */
    public function respondWithChunks(array $chunks): self
    {
        $this->request->setBodySequence($chunks);
        return $this;
    }

    /**
     * Add a delay before responding.
     *
     * @param  float  $seconds  Delay in seconds
     */
    public function delay(float $seconds): self
    {
        $this->request->setDelay($seconds);
        return $this;
    }

    /**
     * Set a random delay range for realistic network simulation.
     *
     * @param  float  $minSeconds  Minimum delay in seconds
     * @param  float  $maxSeconds  Maximum delay in seconds
     */
    public function randomDelay(float $minSeconds, float $maxSeconds): self
    {
        if ($minSeconds > $maxSeconds) {
            throw new \InvalidArgumentException('Minimum delay cannot be greater than maximum delay');
        }

        $randomDelay = $this->generateAggressiveRandomFloat($minSeconds, $maxSeconds);
        $this->request->setDelay($randomDelay);
        return $this;
    }

    /**
     * Create a persistent mock with random delays for each request.
     *
     * @param  float  $minSeconds  Minimum delay in seconds
     * @param  float  $maxSeconds  Maximum delay in seconds
     */
    public function randomPersistentDelay(float $minSeconds, float $maxSeconds): self
    {
        if ($minSeconds > $maxSeconds) {
            throw new \InvalidArgumentException('Minimum delay cannot be greater than maximum delay');
        }

        $this->request->setRandomDelayRange($minSeconds, $maxSeconds);
        $this->persistent();
        return $this;
    }

    /**
     * Simulate a slow response.
     *
     * @param  float  $delaySeconds  Delay in seconds
     */
    public function slowResponse(float $delaySeconds): self
    {
        $this->request->setDelay($delaySeconds);
        return $this;
    }

    /**
     * Make the mock fail with an error.
     *
     * @param  string  $error  Error message
     */
    public function fail(string $error = 'Mocked request failure'): self
    {
        $this->request->setError($error);
        return $this;
    }

    /**
     * Simulate a timeout failure.
     *
     * @param  float  $seconds  Timeout duration in seconds
     */
    public function timeout(float $seconds = 30.0): self
    {
        $this->request->setTimeout($seconds);
        return $this;
    }

    /**
     * Simulate a timeout failure that can be retried.
     *
     * @param  float  $timeoutAfter  Timeout duration in seconds
     * @param  string|null  $customMessage  Custom error message
     */
    public function timeoutFailure(float $timeoutAfter = 30.0, ?string $customMessage = null): self
    {
        if ($customMessage) {
            $this->request->setError($customMessage);
        } else {
            $this->request->setTimeout($timeoutAfter);
        }
        $this->request->setRetryable(true);
        return $this;
    }

    /**
     * Simulate a retryable failure.
     *
     * @param  string  $error  Error message
     */
    public function retryableFailure(string $error = 'Connection failed'): self
    {
        $this->request->setError($error);
        $this->request->setRetryable(true);
        return $this;
    }

    /**
     * Simulate a network error.
     *
     * @param  string  $errorType  Error type: 'connection', 'timeout', 'resolve', 'ssl'
     */
    public function networkError(string $errorType = 'connection'): self
    {
        $errors = [
            'connection' => 'Connection failed',
            'timeout' => 'Connection timed out',
            'resolve' => 'Could not resolve host',
            'ssl' => 'SSL connection timeout',
        ];

        $error = $errors[$errorType] ?? $errorType;
        $this->request->setError($error);
        $this->request->setRetryable(true);
        return $this;
    }

    /**
     * Create multiple mocks that fail until the specified attempt succeeds.
     *
     * @param  int  $successAttempt  Attempt number that should succeed (1-based)
     * @param  string  $failureError  Error message for failed attempts
     */
    public function failUntilAttempt(int $successAttempt, string $failureError = 'Connection failed'): self
    {
        if ($successAttempt < 1) {
            throw new \InvalidArgumentException('Success attempt must be >= 1');
        }

        for ($i = 1; $i < $successAttempt; $i++) {
            $this->handler->addMockedRequest(
                $this->createFailureMock($failureError." (attempt {$i})", true)
            );
        }

        $this->respondWithStatus(200);
        if (empty($this->request->getBody())) {
            $this->respondJson(['success' => true, 'attempt' => $successAttempt]);
        }

        return $this;
    }

    /**
     * Create multiple mocks with different failure types until success.
     *
     * @param  array  $failures  Array of failures (strings or arrays with 'error', 'status', 'delay', 'retryable')
     * @param  string|array|null  $successResponse  The final successful response
     */
    public function failWithSequence(array $failures, string|array|null $successResponse = null): self
    {
        foreach ($failures as $index => $failure) {
            $attemptNumber = $index + 1;

            $mock = new MockedRequest($this->request->method);
            if ($this->request->urlPattern) {
                $mock->setUrlPattern($this->request->urlPattern);
            }

            if (is_string($failure)) {
                $mock->setError($failure." (attempt {$attemptNumber})");
                $mock->setRetryable(true);
            } elseif (is_array($failure)) {
                $error = $failure['error'] ?? 'Request failed';
                $retryable = $failure['retryable'] ?? true;
                $delay = $failure['delay'] ?? 0.1;
                $statusCode = $failure['status'] ?? null;

                if ($statusCode !== null) {
                    $mock->setStatusCode($statusCode);
                    $mock->setBody(json_encode(['error' => $error]));
                    $mock->addResponseHeader('Content-Type', 'application/json');
                } else {
                    $mock->setError($error." (attempt {$attemptNumber})");
                }
                $mock->setRetryable($retryable);
                $mock->setDelay($delay);
            }
            $this->handler->addMockedRequest($mock);
        }

        $this->respondWithStatus(200);

        if ($successResponse !== null) {
            if (is_array($successResponse)) {
                $this->respondJson($successResponse);
            } else {
                $this->respondWith((string) $successResponse);
            }
        } else {
            $this->respondJson(['success' => true, 'attempt' => count($failures) + 1]);
        }

        return $this;
    }

    /**
     * Create timeout failures until success.
     *
     * @param  int  $successAttempt  Attempt number that should succeed
     * @param  float  $timeoutAfter  Timeout duration in seconds
     */
    public function timeoutUntilAttempt(int $successAttempt, float $timeoutAfter = 5.0): self
    {
        for ($i = 1; $i < $successAttempt; $i++) {
            $mock = new MockedRequest($this->request->method ?? '*');
            $mock->setUrlPattern($this->request->urlPattern);
            $mock->setTimeout($timeoutAfter);
            $mock->setRetryable(true);
            $this->handler->addMockedRequest($mock);
        }

        $this->respondWithStatus(200);
        if (empty($this->request->getBody())) {
            $this->respondJson(['success' => true, 'attempt' => $successAttempt, 'message' => 'Success after timeouts']);
        }

        return $this;
    }

    /**
     * Create HTTP status code failures until success.
     *
     * @param  int  $successAttempt  Attempt number that should succeed
     * @param  int  $failureStatus  HTTP status code for failures (default: 500)
     */
    public function statusFailuresUntilAttempt(int $successAttempt, int $failureStatus = 500): self
    {
        for ($i = 1; $i < $successAttempt; $i++) {
            $mock = new MockedRequest($this->request->method ?? '*');
            $mock->setUrlPattern($this->request->urlPattern);
            $mock->setStatusCode($failureStatus);
            $mock->setBody(json_encode(['error' => "Server error on attempt {$i}"]));
            $mock->addResponseHeader('Content-Type', 'application/json');

            if (in_array($failureStatus, [408, 429, 500, 502, 503, 504])) {
                $mock->setRetryable(true);
            }

            $this->handler->addMockedRequest($mock);
        }

        $this->respondWithStatus(200);
        if (empty($this->request->getBody())) {
            $this->respondJson(['success' => true, 'attempt' => $successAttempt]);
        }

        return $this;
    }

    /**
     * Create a mixed sequence of different failure types.
     *
     * @param  int  $successAttempt  Attempt number that should succeed
     */
    public function mixedFailuresUntilAttempt(int $successAttempt): self
    {
        $failureTypes = ['timeout', 'connection', 'dns', 'ssl'];

        for ($i = 1; $i < $successAttempt; $i++) {
            $failureType = $failureTypes[($i - 1) % count($failureTypes)];

            $mock = new MockedRequest($this->request->method ?? '*');
            $mock->setUrlPattern($this->request->urlPattern);

            switch ($failureType) {
                case 'timeout':
                    $mock->setTimeout(2.0);
                    break;
                case 'connection':
                    $mock->setError("Connection failed (attempt {$i})");
                    $mock->setRetryable(true);
                    break;
                case 'dns':
                    $mock->setError("Could not resolve host (attempt {$i})");
                    $mock->setRetryable(true);
                    break;
                case 'ssl':
                    $mock->setError("SSL connection timeout (attempt {$i})");
                    $mock->setRetryable(true);
                    break;
            }

            $this->handler->addMockedRequest($mock);
        }

        $this->respondWithStatus(200);
        if (empty($this->request->getBody())) {
            $this->respondJson([
                'success' => true,
                'attempt' => $successAttempt,
                'message' => 'Success after mixed failures',
            ]);
        }

        return $this;
    }

    /**
     * Create gradually improving response times (simulate network recovery).
     *
     * @param  int  $successAttempt  Attempt number that should succeed
     * @param  float  $maxDelay  Maximum delay in seconds
     */
    public function slowlyImproveUntilAttempt(int $successAttempt, float $maxDelay = 10.0): self
    {
        for ($i = 1; $i < $successAttempt; $i++) {
            $delay = $maxDelay * (($successAttempt - $i) / ($successAttempt - 1));

            if ($delay > 5.0) {
                $mock = new MockedRequest($this->request->method ?? '*');
                $mock->setUrlPattern($this->request->urlPattern);
                $mock->setTimeout($delay);
                $mock->setRetryable(true);
            } else {
                $mock = new MockedRequest($this->request->method ?? '*');
                $mock->setUrlPattern($this->request->urlPattern);
                $mock->setStatusCode(200);
                $mock->setBody(json_encode(['attempt' => $i, 'delay' => $delay, 'status' => 'slow']));
                $mock->setDelay($delay);
            }

            $this->handler->addMockedRequest($mock);
        }

        $this->respondWithStatus(200);
        $this->respondJson(['success' => true, 'attempt' => $successAttempt, 'message' => 'Network recovered']);

        return $this;
    }

    /**
     * Simulate rate limiting with exponential backoff.
     *
     * @param  int  $successAttempt  Attempt number that should succeed
     */
    public function rateLimitedUntilAttempt(int $successAttempt): self
    {
        for ($i = 1; $i < $successAttempt; $i++) {
            $mock = new MockedRequest($this->request->method ?? '*');
            $mock->setUrlPattern($this->request->urlPattern);
            $mock->setStatusCode(429);
            $mock->setBody(json_encode([
                'error' => 'Too Many Requests',
                'retry_after' => pow(2, $i),
                'attempt' => $i,
            ]));
            $mock->addResponseHeader('Content-Type', 'application/json');
            $mock->addResponseHeader('Retry-After', (string) pow(2, $i));
            $mock->setRetryable(true);

            $this->handler->addMockedRequest($mock);
        }

        $this->respondWithStatus(200);
        if (empty($this->request->getBody())) {
            $this->respondJson(['success' => true, 'attempt' => $successAttempt, 'message' => 'Rate limit cleared']);
        }

        return $this;
    }

    /**
     * Create intermittent failures (some succeed, some fail).
     *
     * @param  array<bool>  $pattern  Array of booleans (true = fail, false = succeed)
     */
    public function intermittentFailures(array $pattern): self
    {
        foreach ($pattern as $index => $shouldFail) {
            $attemptNumber = $index + 1;
            $mock = new MockedRequest($this->request->method ?? '*');
            $mock->setUrlPattern($this->request->urlPattern);

            if ($shouldFail) {
                $mock->setError("Intermittent failure on attempt {$attemptNumber}");
                $mock->setRetryable(true);
            } else {
                $mock->setStatusCode(200);
                $mock->setBody(json_encode(['success' => true, 'attempt' => $attemptNumber]));
                $mock->addResponseHeader('Content-Type', 'application/json');
            }

            $this->handler->addMockedRequest($mock);
        }

        return $this;
    }

    /**
     * Mock a file download response.
     *
     * @param  string  $content  File content
     * @param  string|null  $filename  Optional filename for Content-Disposition header
     * @param  string  $contentType  MIME type (default: application/octet-stream)
     */
    public function downloadFile(string $content, ?string $filename = null, string $contentType = 'application/octet-stream'): self
    {
        $this->request->setBody($content);
        $this->request->addResponseHeader('Content-Type', $contentType);
        $this->request->addResponseHeader('Content-Length', (string) strlen($content));

        if ($filename !== null) {
            $this->request->addResponseHeader('Content-Disposition', "attachment; filename=\"{$filename}\"");
        }

        return $this;
    }

    /**
     * Mock a large file download with generated content.
     *
     * @param  int  $sizeInKB  File size in kilobytes
     * @param  string|null  $filename  Optional filename
     */
    public function downloadLargeFile(int $sizeInKB = 100, ?string $filename = null): self
    {
        $content = str_repeat('MOCK_FILE_DATA_', $sizeInKB * 64);
        return $this->downloadFile($content, $filename, 'application/octet-stream');
    }

    /**
     * Configure the mock to set cookies via Set-Cookie headers.
     *
     * @param  array  $cookies  Cookie configurations
     */
    public function setCookies(array $cookies): self
    {
        $cookieService = new CookieManager;
        $cookieService->mockSetCookies($this->request, $cookies);
        return $this;
    }

    /**
     * Set a single cookie via Set-Cookie header.
     *
     * @param  string  $name  Cookie name
     * @param  string  $value  Cookie value
     * @param  string|null  $path  Cookie path
     * @param  string|null  $domain  Cookie domain
     * @param  int|null  $expires  Expiration timestamp
     * @param  bool  $secure  Secure flag
     * @param  bool  $httpOnly  HttpOnly flag
     * @param  string|null  $sameSite  SameSite attribute
     */
    public function setCookie(
        string $name,
        string $value,
        ?string $path = '/',
        ?string $domain = null,
        ?int $expires = null,
        bool $secure = false,
        bool $httpOnly = false,
        ?string $sameSite = null
    ): self {
        $config = compact('value', 'path', 'domain', 'expires', 'secure', 'httpOnly', 'sameSite');
        $config = array_filter($config, fn ($v) => $v !== null);

        return $this->setCookies([$name => $config]);
    }


    /**
     * Make this mock persistent (reusable for multiple requests).
     */
    public function persistent(): self
    {
        $this->request->setPersistent(true);
        return $this;
    }

    /**
     * Register this mock with the testing handler.
     */
    public function register(): void
    {
        $this->handler->addMockedRequest($this->request);
    }

    /**
     * Generate aggressive random float with high precision.
     */
    private function generateAggressiveRandomFloat(float $min, float $max): float
    {
        $precision = 1000000;
        $randomInt = random_int(
            (int) ($min * $precision),
            (int) ($max * $precision)
        );

        return $randomInt / $precision;
    }

    /**
     * Create a failure mock for retry scenarios.
     */
    private function createFailureMock(string $error, bool $retryable): MockedRequest
    {
        $mock = new MockedRequest($this->request->method ?? '*');
        if ($this->request->urlPattern) {
            $mock->setUrlPattern($this->request->urlPattern);
        }
        $mock->setError($error);
        $mock->setRetryable($retryable);
        $mock->setDelay(0.1);

        return $mock;
    }
}