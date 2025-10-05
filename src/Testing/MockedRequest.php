<?php

namespace Hibla\Http\Testing;

/**
 * Represents a mocked HTTP request with matching criteria and response data.
 */
class MockedRequest
{
    /**
     * HTTP method to match (or '*' for any method).
     */
    public string $method;

    /**
     * URL pattern to match using fnmatch.
     */
    public ?string $urlPattern = null;

    /**
     * Headers that must be present in the request.
     *
     * @var array<string, string>
     */
    private array $headerMatchers = [];

    /**
     * Pattern to match against request body.
     */
    private ?string $bodyMatcher = null;

    /**
     * Expected JSON structure for request body.
     *
     * @var array<string, mixed>|null
     */
    private ?array $jsonMatcher = null;

    /**
     * Minimum random delay in seconds.
     */
    private ?float $randomDelayMin = null;

    /**
     * Maximum random delay in seconds.
     */
    private ?float $randomDelayMax = null;

    /**
     * HTTP status code to return.
     */
    private int $statusCode = 200;

    /**
     * Response body content.
     */
    private string $body = '';

    /**
     * Response body chunks for streaming simulation.
     *
     * @var array<string>
     */
    private array $bodySequence = [];

    /**
     * Response headers to return.
     *
     * @var array<string, string|array<string>>
     */
    private array $headers = [];

    /**
     * Fixed delay in seconds before responding.
     */
    private float $delay = 0;

    /**
     * Error message if this mock should fail.
     */
    private ?string $error = null;

    /**
     * Whether this mock can be reused multiple times.
     */
    private bool $persistent = false;

    /**
     * Timeout duration in seconds.
     */
    private ?float $timeoutAfter = null;

    /**
     * Whether the failure should be retryable.
     */
    private bool $isRetryable = false;

    /**
     * Whether this is a Server-Sent Events response.
     */
    private bool $isSSE = false;

    /**
     * SSE events to emit.
     *
     * @var array<array{id?: string, event?: string, data?: string, retry?: int}>
     */
    private array $sseEvents = [];

    /**
     * Creates a new mocked request.
     *
     * @param string $method HTTP method to match (default: '*' for any)
     */
    public function __construct(string $method = '*')
    {
        $this->method = $method;
    }

    /**
     * Adds a single SSE event to the events array.
     *
     * @param array{id?: string, event?: string, data?: string, retry?: int} $event
     */
    public function addSSEEvent(array $event): void
    {
        $this->sseEvents[] = $event;
    }

    /**
     * Sets the URL pattern to match.
     *
     * @param string $pattern URL pattern using fnmatch syntax
     */
    public function setUrlPattern(string $pattern): void
    {
        $this->urlPattern = $pattern;
    }

    /**
     * Adds a header that must be present in the request.
     *
     * @param string $name Header name
     * @param string $value Expected header value
     */
    public function addHeaderMatcher(string $name, string $value): void
    {
        $this->headerMatchers[strtolower($name)] = $value;
    }

    /**
     * Sets a pattern to match against the request body.
     *
     * @param string $pattern Pattern using fnmatch syntax
     */
    public function setBodyMatcher(string $pattern): void
    {
        $this->bodyMatcher = $pattern;
    }

    /**
     * Sets expected JSON structure for the request body.
     *
     * @param array<string, mixed> $data Expected JSON data
     */
    public function setJsonMatcher(array $data): void
    {
        $this->jsonMatcher = $data;
    }

    /**
     * Sets the HTTP status code to return.
     *
     * @param int $code HTTP status code
     */
    public function setStatusCode(int $code): void
    {
        $this->statusCode = $code;
    }

    /**
     * Sets the response body content.
     *
     * @param string $body Response body
     */
    public function setBody(string $body): void
    {
        $this->body = $body;
        $this->bodySequence = [];
    }

    /**
     * Sets response body as a sequence of chunks for streaming.
     *
     * @param array<string> $chunks Body chunks
     */
    public function setBodySequence(array $chunks): void
    {
        $this->bodySequence = $chunks;
        $this->body = implode('', $chunks);
    }

    /**
     * Adds a response header.
     *
     * @param string $name Header name
     * @param string $value Header value
     */
    public function addResponseHeader(string $name, string $value): void
    {
        if (isset($this->headers[$name])) {
            if (! is_array($this->headers[$name])) {
                $this->headers[$name] = [$this->headers[$name]];
            }
            $this->headers[$name][] = $value;
        } else {
            $this->headers[$name] = $value;
        }
    }

    /**
     * Sets a fixed delay before responding.
     *
     * @param float $seconds Delay in seconds
     */
    public function setDelay(float $seconds): void
    {
        $this->delay = $seconds;
    }

    /**
     * Sets an error message to make this mock fail.
     *
     * @param string $error Error message
     */
    public function setError(string $error): void
    {
        $this->error = $error;
    }

    /**
     * Sets a timeout for this mock.
     *
     * @param float $seconds Timeout in seconds
     */
    public function setTimeout(float $seconds): void
    {
        $this->timeoutAfter = $seconds;
        $this->error = sprintf('Connection timed out after %.1fs', $seconds);
    }

    /**
     * Gets the timeout duration.
     *
     * @return float|null Timeout in seconds or null
     */
    public function getTimeoutDuration(): ?float
    {
        return $this->timeoutAfter;
    }

    /**
     * Sets whether the failure should be retryable.
     *
     * @param bool $retryable True if retryable
     */
    public function setRetryable(bool $retryable): void
    {
        $this->isRetryable = $retryable;
    }

    /**
     * Sets whether this mock can be reused.
     *
     * @param bool $persistent True if persistent
     */
    public function setPersistent(bool $persistent): void
    {
        $this->persistent = $persistent;
    }

    /**
     * Checks if this mock matches the given request.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param array<int|string, mixed> $options cURL options
     * @return bool True if matches
     */
    public function matches(string $method, string $url, array $options): bool
    {
        if ($this->method !== '*' && strtoupper($this->method) !== strtoupper($method)) {
            return false;
        }

        if ($this->urlPattern !== null) {
            if (! $this->urlMatches($this->urlPattern, $url)) {
                return false;
            }
        }

        if ($this->headerMatchers !== []) {
            $requestHeaders = $this->extractHeaders($options);
            foreach ($this->headerMatchers as $name => $expectedValue) {
                $actualValue = $requestHeaders[strtolower($name)] ?? null;
                if ($actualValue !== $expectedValue) {
                    return false;
                }
            }
        }

        if ($this->bodyMatcher !== null) {
            $body = $options[CURLOPT_POSTFIELDS] ?? '';
            if (! is_string($body)) {
                return false;
            }
            if (! fnmatch($this->bodyMatcher, $body)) {
                return false;
            }
        }

        if ($this->jsonMatcher !== null) {
            $body = $options[CURLOPT_POSTFIELDS] ?? '';
            if (! is_string($body)) {
                return false;
            }
            $decoded = json_decode($body, true);
            if ($decoded !== $this->jsonMatcher) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks if URL matches the pattern with lenient trailing slash handling.
     *
     * @param string $pattern URL pattern
     * @param string $url URL to match
     * @return bool True if matches
     */
    private function urlMatches(string $pattern, string $url): bool
    {
        if (fnmatch($pattern, $url)) {
            return true;
        }

        $normalizedPattern = rtrim($pattern, '/');
        $normalizedUrl = rtrim($url, '/');

        if (fnmatch($normalizedPattern, $normalizedUrl)) {
            return true;
        }

        if (fnmatch($normalizedPattern . '/', $normalizedUrl . '/')) {
            return true;
        }

        return false;
    }

    /**
     * Gets the HTTP status code.
     *
     * @return int Status code
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Gets the response body.
     *
     * @return string Response body
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Gets the response body sequence.
     *
     * @return array<string> Body chunks
     */
    public function getBodySequence(): array
    {
        return $this->bodySequence;
    }

    /**
     * Gets the response headers.
     *
     * @return array<string, string|array<string>> Headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Sets random delay range for persistent mocks.
     *
     * @param float $min Minimum delay in seconds
     * @param float $max Maximum delay in seconds
     */
    public function setRandomDelayRange(float $min, float $max): void
    {
        $this->randomDelayMin = $min;
        $this->randomDelayMax = $max;
    }

    /**
     * Gets the random delay range.
     *
     * @return array{0: float, 1: float}|null Delay range or null
     */
    public function getRandomDelayRange(): ?array
    {
        if ($this->randomDelayMin === null || $this->randomDelayMax === null) {
            return null;
        }

        return [$this->randomDelayMin, $this->randomDelayMax];
    }

    /**
     * Generates a new random delay for this request.
     *
     * @return float Random delay in seconds
     */
    public function generateRandomDelay(): float
    {
        if ($this->randomDelayMin === null || $this->randomDelayMax === null) {
            return $this->delay;
        }

        $precision = 1000000;
        $randomInt = random_int(
            (int) ($this->randomDelayMin * $precision),
            (int) ($this->randomDelayMax * $precision)
        );

        return $randomInt / $precision;
    }

    /**
     * Gets the delay, generating random delay if range is set.
     *
     * @return float Delay in seconds
     */
    public function getDelay(): float
    {
        if ($this->randomDelayMin !== null && $this->randomDelayMax !== null) {
            return $this->generateRandomDelay();
        }

        return $this->timeoutAfter ?? $this->delay;
    }

    /**
     * Gets the error message.
     *
     * @return string|null Error message or null
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Checks if this mock should fail.
     *
     * @return bool True if should fail
     */
    public function shouldFail(): bool
    {
        return $this->error !== null;
    }

    /**
     * Gets the HTTP method.
     *
     * @return string HTTP method
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Gets the URL pattern.
     *
     * @return string|null URL pattern or null
     */
    public function getUrlPattern(): ?string
    {
        return $this->urlPattern;
    }

    /**
     * Checks if this mock is persistent.
     *
     * @return bool True if persistent
     */
    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    /**
     * Checks if this mock represents a timeout.
     *
     * @return bool True if timeout
     */
    public function isTimeout(): bool
    {
        return $this->timeoutAfter !== null;
    }

    /**
     * Checks if this failure is retryable.
     *
     * @return bool True if retryable
     */
    public function isRetryableFailure(): bool
    {
        return $this->isRetryable;
    }

    /**
     * Marks this mock as an SSE response.
     */
    public function asSSE(): void
    {
        $this->isSSE = true;
    }

    /**
     * Checks if this is an SSE mock.
     *
     * @return bool True if SSE
     */
    public function isSSE(): bool
    {
        return $this->isSSE;
    }

    /**
     * Sets SSE events to emit.
     *
     * @param array<array{id?: string, event?: string, data?: string, retry?: int}> $events SSE events
     */
    public function setSSEEvents(array $events): void
    {
        $this->sseEvents = $events;
    }

    /**
     * Gets SSE events.
     *
     * @return array<array{id?: string, event?: string, data?: string, retry?: int}> SSE events
     */
    public function getSSEEvents(): array
    {
        return $this->sseEvents;
    }

    /**
     * Extracts headers from cURL options.
     *
     * @param array<int|string, mixed> $options cURL options
     * @return array<string, string> Extracted headers
     */
    private function extractHeaders(array $options): array
    {
        $headers = [];
        $httpHeaders = $options[CURLOPT_HTTPHEADER] ?? null;

        if (! is_array($httpHeaders)) {
            return $headers;
        }

        foreach ($httpHeaders as $header) {
            if (! is_string($header)) {
                continue;
            }
            if (strpos($header, ':') !== false) {
                [$name, $value] = explode(':', $header, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return $headers;
    }

    /**
     * Converts the mock to an array representation.
     *
     * @return array<string, mixed> Array representation
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'urlPattern' => $this->urlPattern,
            'headerMatchers' => $this->headerMatchers,
            'bodyMatcher' => $this->bodyMatcher,
            'jsonMatcher' => $this->jsonMatcher,
            'statusCode' => $this->statusCode,
            'body' => $this->body,
            'headers' => $this->headers,
            'delay' => $this->delay,
            'randomDelayMin' => $this->randomDelayMin,
            'randomDelayMax' => $this->randomDelayMax,
            'error' => $this->error,
            'persistent' => $this->persistent,
            'timeoutAfter' => $this->timeoutAfter,
            'isRetryable' => $this->isRetryable,
            'isSSE' => $this->isSSE,
            'sseEvents' => $this->sseEvents,
        ];
    }

    /**
     * Creates a MockedRequest from an array representation.
     *
     * @param array<string, mixed> $data Array data
     * @return self MockedRequest instance
     */
    public static function fromArray(array $data): self
    {
        $method = $data['method'] ?? '*';
        if (! is_string($method)) {
            $method = '*';
        }

        $request = new self($method);

        $urlPattern = $data['urlPattern'] ?? null;
        $request->urlPattern = is_string($urlPattern) ? $urlPattern : null;

        $headerMatchers = $data['headerMatchers'] ?? [];
        if (is_array($headerMatchers)) {
            /** @var array<string, string> $validatedHeaders */
            $validatedHeaders = [];
            foreach ($headerMatchers as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $validatedHeaders[$key] = $value;
                }
            }
            $request->headerMatchers = $validatedHeaders;
        }

        $bodyMatcher = $data['bodyMatcher'] ?? null;
        $request->bodyMatcher = is_string($bodyMatcher) ? $bodyMatcher : null;

        $jsonMatcher = $data['jsonMatcher'] ?? null;
        if (is_array($jsonMatcher)) {
            /** @var array<string, mixed> $typedJsonMatcher */
            $typedJsonMatcher = [];
            foreach ($jsonMatcher as $key => $value) {
                if (is_string($key)) {
                    $typedJsonMatcher[$key] = $value;
                }
            }
            $request->jsonMatcher = $typedJsonMatcher;
        } else {
            $request->jsonMatcher = null;
        }

        $statusCode = $data['statusCode'] ?? 200;
        $request->statusCode = is_int($statusCode) ? $statusCode : 200;

        $body = $data['body'] ?? '';
        $request->body = is_string($body) ? $body : '';

        $headers = $data['headers'] ?? [];
        if (is_array($headers)) {
            /** @var array<string, string|array<string>> $validatedHeaders */
            $validatedHeaders = [];
            foreach ($headers as $key => $value) {
                if (is_string($key)) {
                    if (is_string($value)) {
                        $validatedHeaders[$key] = $value;
                    } elseif (is_array($value)) {
                        $stringArray = array_filter($value, 'is_string');
                        if ($stringArray !== []) {
                            $validatedHeaders[$key] = array_values($stringArray);
                        }
                    }
                }
            }
            $request->headers = $validatedHeaders;
        }

        $delay = $data['delay'] ?? 0;
        $request->delay = is_float($delay) || is_int($delay) ? (float)$delay : 0.0;

        $randomDelayMin = $data['randomDelayMin'] ?? null;
        $request->randomDelayMin = is_float($randomDelayMin) || is_int($randomDelayMin) ? (float)$randomDelayMin : null;

        $randomDelayMax = $data['randomDelayMax'] ?? null;
        $request->randomDelayMax = is_float($randomDelayMax) || is_int($randomDelayMax) ? (float)$randomDelayMax : null;

        $error = $data['error'] ?? null;
        $request->error = is_string($error) ? $error : null;

        $persistent = $data['persistent'] ?? false;
        $request->persistent = is_bool($persistent) ? $persistent : false;

        $timeoutAfter = $data['timeoutAfter'] ?? null;
        $request->timeoutAfter = is_float($timeoutAfter) || is_int($timeoutAfter) ? (float)$timeoutAfter : null;

        $isRetryable = $data['isRetryable'] ?? false;
        $request->isRetryable = is_bool($isRetryable) ? $isRetryable : false;

        $isSSE = $data['isSSE'] ?? false;
        $request->isSSE = is_bool($isSSE) ? $isSSE : false;

        $sseEvents = $data['sseEvents'] ?? [];
        if (is_array($sseEvents)) {
            /** @var array<array{id?: string, event?: string, data?: string, retry?: int}> $validatedEvents */
            $validatedEvents = [];
            foreach ($sseEvents as $event) {
                if (is_array($event)) {
                    /** @var array{id?: string, event?: string, data?: string, retry?: int} $validatedEvent */
                    $validatedEvent = [];

                    if (isset($event['id']) && is_string($event['id'])) {
                        $validatedEvent['id'] = $event['id'];
                    }
                    if (isset($event['event']) && is_string($event['event'])) {
                        $validatedEvent['event'] = $event['event'];
                    }
                    if (isset($event['data']) && is_string($event['data'])) {
                        $validatedEvent['data'] = $event['data'];
                    }
                    if (isset($event['retry']) && is_int($event['retry'])) {
                        $validatedEvent['retry'] = $event['retry'];
                    }

                    $validatedEvents[] = $validatedEvent;
                }
            }
            $request->sseEvents = $validatedEvents;
        }

        return $request;
    }
}
