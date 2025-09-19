<?php

namespace Hibla\Http\Testing;

use Hibla\Http\Testing\Utilities\CookieManager;

/**
 * Builder for creating mocked requests with fluent interface.
 */
class MockRequestBuilder
{
    private TestingHttpHandler $handler;
    private MockedRequest $request;

    public function __construct(TestingHttpHandler $handler, string $method = '*')
    {
        $this->handler = $handler;
        $this->request = new MockedRequest($method);
    }

    public function url(string $pattern): self
    {
        $this->request->setUrlPattern($pattern);

        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->request->addHeaderMatcher($name, $value);

        return $this;
    }

    public function withBody(string $pattern): self
    {
        $this->request->setBodyMatcher($pattern);

        return $this;
    }

    public function withJson(array $data): self
    {
        $this->request->setJsonMatcher($data);

        return $this;
    }

    public function respondWithStatus(int $status = 200): self
    {
        $this->request->setStatusCode($status);

        return $this;
    }

    public function body(string $body): self
    {
        $this->request->setBody($body);

        return $this;
    }

    /**
     * Sets a sequence of body chunks to simulate a multi-chunk stream.
     * This will cause the onChunk callback to be fired for each item in the array.
     *
     * @param  array<string>  $chunks
     */
    public function bodies(array $chunks): self
    {
        $this->request->setBodySequence($chunks);

        return $this;
    }

    public function json(array $data): self
    {
        $this->request->setBody(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $this->request->addResponseHeader('Content-Type', 'application/json');

        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->request->addResponseHeader($name, $value);

        return $this;
    }

    public function delay(float $seconds): self
    {
        $this->request->setDelay($seconds);

        return $this;
    }

    /**
     * Set a random delay range for more realistic network simulation.
     * Uses aggressive randomization for realistic variation.
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
     * Generate aggressive random float with high precision for realistic network simulation.
     *
     * @param  float  $min  Minimum value
     * @param  float  $max  Maximum value
     * @return float Random float with microsecond precision
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
     * Create a persistent mock with random delays for each request.
     * Each request will have a different random delay within the specified range.
     *
     * @param  float  $minSeconds  Minimum delay in seconds
     * @param  float  $maxSeconds  Maximum delay in seconds
     */
    public function randomPersistentDelay(float $minSeconds, float $maxSeconds): self
    {
        if ($minSeconds > $maxSeconds) {
            throw new \InvalidArgumentException('Minimum delay cannot be greater than maximum delay');
        }

        // Store the delay range in the request for later use
        $this->request->setRandomDelayRange($minSeconds, $maxSeconds);
        $this->persistent();

        return $this;
    }

    public function fail(string $error = 'Mocked request failure'): self
    {
        $this->request->setError($error);

        return $this;
    }

    public function timeout(float $seconds = 30.0): self
    {
        $this->request->setTimeout($seconds);

        return $this;
    }

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

    public function slowResponse(float $delaySeconds): self
    {
        $this->request->setDelay($delaySeconds);

        return $this;
    }

    public function retryableFailure(string $error = 'Connection failed'): self
    {
        $this->request->setError($error);
        $this->request->setRetryable(true);

        return $this;
    }

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

    public function persistent(): self
    {
        $this->request->setPersistent(true);

        return $this;
    }

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

    public function downloadLargeFile(int $sizeInKB = 100, ?string $filename = null): self
    {
        $content = str_repeat('MOCK_FILE_DATA_', $sizeInKB * 64);

        return $this->downloadFile($content, $filename, 'application/octet-stream');
    }

    /**
     * Create multiple mocks that fail until the specified attempt succeeds
     */
    public function failUntilAttempt(int $successAttempt, string $failureError = 'Connection failed'): self
    {
        if ($successAttempt < 1) {
            throw new \InvalidArgumentException('Success attempt must be >= 1');
        }

        // Create failure mocks for attempts 1 through (successAttempt - 1)
        for ($i = 1; $i < $successAttempt; $i++) {
            $this->handler->addMockedRequest(
                $this->createFailureMock($failureError." (attempt {$i})", true)
            );
        }

        // Set up this builder instance as the success response
        $this->respondWithStatus(200);
        if (empty($this->request->getBody())) {
            $this->json(['success' => true, 'attempt' => $successAttempt]);
        }

        return $this;
    }

    /**
     * Create multiple mocks with different failure types until success.
     *
     * @param  array  $failures  An array defining the sequence of failures.
     * @param  string|array|null  $successResponse  The body for the final successful response. Can be a string or an array for JSON.
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
                $this->json($successResponse);
            } else {
                $this->body((string) $successResponse);
            }
        } else {
            $this->json(['success' => true, 'attempt' => count($failures) + 1]);
        }

        return $this;
    }

    /**
     * Create timeout failures until success
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
            $this->json(['success' => true, 'attempt' => $successAttempt, 'message' => 'Success after timeouts']);
        }

        return $this;
    }

    /**
     * Create HTTP status code failures until success
     */
    public function statusFailuresUntilAttempt(int $successAttempt, int $failureStatus = 500): self
    {
        for ($i = 1; $i < $successAttempt; $i++) {
            $mock = new MockedRequest($this->request->method ?? '*');
            $mock->setUrlPattern($this->request->urlPattern);
            $mock->setStatusCode($failureStatus);
            $mock->setBody(json_encode(['error' => "Server error on attempt {$i}"]));
            $mock->addResponseHeader('Content-Type', 'application/json');

            // Status codes 408, 429, 500, 502, 503, 504 are typically retryable
            if (in_array($failureStatus, [408, 429, 500, 502, 503, 504])) {
                $mock->setRetryable(true);
            }

            $this->handler->addMockedRequest($mock);
        }

        $this->respondWithStatus(200);
        if (empty($this->request->getBody())) {
            $this->json(['success' => true, 'attempt' => $successAttempt]);
        }

        return $this;
    }

    /**
     * Create a mixed sequence of different failure types
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
            $this->json([
                'success' => true,
                'attempt' => $successAttempt,
                'message' => 'Success after mixed failures',
            ]);
        }

        return $this;
    }

    /**
     * Create gradually improving response times (simulate network recovery)
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
        $this->json(['success' => true, 'attempt' => $successAttempt, 'message' => 'Network recovered']);

        return $this;
    }

    /**
     * Simulate rate limiting with backoff
     */
    public function rateLimitedUntilAttempt(int $successAttempt): self
    {
        for ($i = 1; $i < $successAttempt; $i++) {
            $mock = new MockedRequest($this->request->method ?? '*');
            $mock->setUrlPattern($this->request->urlPattern);
            $mock->setStatusCode(429);
            $mock->setBody(json_encode([
                'error' => 'Too Many Requests',
                'retry_after' => pow(2, $i), // Exponential backoff
                'attempt' => $i,
            ]));
            $mock->addResponseHeader('Content-Type', 'application/json');
            $mock->addResponseHeader('Retry-After', (string) pow(2, $i));
            $mock->setRetryable(true);

            $this->handler->addMockedRequest($mock);
        }

        $this->respondWithStatus(200);
        if (empty($this->request->getBody())) {
            $this->json(['success' => true, 'attempt' => $successAttempt, 'message' => 'Rate limit cleared']);
        }

        return $this;
    }

    /**
     * Create intermittent failures (some succeed, some fail)
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
     * Configure this mock to set cookies via Set-Cookie headers.
     */
    public function setCookies(array $cookies): self
    {
        $cookieService = new CookieManager;
        $cookieService->mockSetCookies($this->request, $cookies);

        return $this;
    }

    /**
     * Set a single cookie via Set-Cookie header.
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
     * Require specific cookies to be present in the request.
     */
    public function expectCookies(array $expectedCookies): self
    {
        foreach ($expectedCookies as $name => $value) {
            $this->request->addHeaderMatcher('cookie', $name.'='.$value);
        }

        return $this;
    }

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

    public function register(): void
    {
        $this->handler->addMockedRequest($this->request);
    }
}
