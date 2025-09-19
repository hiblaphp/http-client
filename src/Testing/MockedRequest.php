<?php

namespace Hibla\Http\Testing;

/**
 * Represents a mocked HTTP request with matching criteria and response data.
 */
class MockedRequest
{
    public string $method;
    public ?string $urlPattern = null;
    private array $headerMatchers = [];
    private ?string $bodyMatcher = null;
    private ?array $jsonMatcher = null;
    private ?float $randomDelayMin = null;
    private ?float $randomDelayMax = null;

    private int $statusCode = 200;
    private string $body = '';
    /** @var array<string> A sequence of body chunks for streaming simulation. */
    private array $bodySequence = [];
    private array $headers = [];
    private float $delay = 0;
    private ?string $error = null;
    private bool $persistent = false;
    private ?float $timeoutAfter = null;
    private bool $isRetryable = false;

    public function __construct(string $method = '*')
    {
        $this->method = $method;
    }

    public function setUrlPattern(string $pattern): void
    {
        $this->urlPattern = $pattern;
    }

    public function addHeaderMatcher(string $name, string $value): void
    {
        $this->headerMatchers[strtolower($name)] = $value;
    }

    public function setBodyMatcher(string $pattern): void
    {
        $this->bodyMatcher = $pattern;
    }

    public function setJsonMatcher(array $data): void
    {
        $this->jsonMatcher = $data;
    }

    public function setStatusCode(int $code): void
    {
        $this->statusCode = $code;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
        $this->bodySequence = []; // Clear sequence if a single body is set
    }

    /**
     * @param  array<string>  $chunks
     */
    public function setBodySequence(array $chunks): void
    {
        $this->bodySequence = $chunks;
        $this->body = implode('', $chunks);
    }

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

    public function setDelay(float $seconds): void
    {
        $this->delay = $seconds;
    }

    public function setError(string $error): void
    {
        $this->error = $error;
    }

    public function setTimeout(float $seconds): void
    {
        $this->timeoutAfter = $seconds;
        $this->error = sprintf('Connection timed out after %.1fs', $seconds);
    }

    public function getTimeoutDuration(): ?float
    {
        return $this->timeoutAfter;
    }

    public function setRetryable(bool $retryable): void
    {
        $this->isRetryable = $retryable;
    }

    public function setPersistent(bool $persistent): void
    {
        $this->persistent = $persistent;
    }

    /**
     * Check if this mock matches the given request.
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

        if (! empty($this->headerMatchers)) {
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
            if (! fnmatch($this->bodyMatcher, $body)) {
                return false;
            }
        }

        if ($this->jsonMatcher !== null) {
            $body = $options[CURLOPT_POSTFIELDS] ?? '';
            $decoded = json_decode($body, true);
            if ($decoded !== $this->jsonMatcher) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if URL matches the pattern, with lenient trailing slash handling
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

        if (fnmatch($normalizedPattern.'/', $normalizedUrl.'/')) {
            return true;
        }

        return false;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @return array<string>
     */
    public function getBodySequence(): array
    {
        return $this->bodySequence;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Set random delay range for persistent mocks.
     */
    public function setRandomDelayRange(float $min, float $max): void
    {
        $this->randomDelayMin = $min;
        $this->randomDelayMax = $max;
    }

    /**
     * Get random delay range.
     */
    public function getRandomDelayRange(): ?array
    {
        if ($this->randomDelayMin === null || $this->randomDelayMax === null) {
            return null;
        }

        return [$this->randomDelayMin, $this->randomDelayMax];
    }

    /**
     * Generate a new random delay for this request.
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
     * Get delay, generating random delay if range is set.
     */
    public function getDelay(): float
    {
        if ($this->randomDelayMin !== null && $this->randomDelayMax !== null) {
            return $this->generateRandomDelay();
        }

        return $this->timeoutAfter ?? $this->delay;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function shouldFail(): bool
    {
        return $this->error !== null;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUrlPattern(): ?string
    {
        return $this->urlPattern;
    }

    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    public function isTimeout(): bool
    {
        return $this->timeoutAfter !== null;
    }

    public function isRetryableFailure(): bool
    {
        return $this->isRetryable;
    }

    private function extractHeaders(array $options): array
    {
        $headers = [];
        if (isset($options[CURLOPT_HTTPHEADER])) {
            foreach ($options[CURLOPT_HTTPHEADER] as $header) {
                if (strpos($header, ':') !== false) {
                    [$name, $value] = explode(':', $header, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
            }
        }

        return $headers;
    }

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
        ];
    }

    public static function fromArray(array $data): self
    {
        $request = new self($data['method']);
        $request->urlPattern = $data['urlPattern'];
        $request->headerMatchers = $data['headerMatchers'] ?? [];
        $request->bodyMatcher = $data['bodyMatcher'];
        $request->jsonMatcher = $data['jsonMatcher'];
        $request->statusCode = $data['statusCode'];
        $request->body = $data['body'];
        $request->headers = $data['headers'] ?? [];
        $request->delay = $data['delay'] ?? 0;
        $request->randomDelayMin = $data['randomDelayMin'] ?? null;
        $request->randomDelayMax = $data['randomDelayMax'] ?? null;
        $request->error = $data['error'];
        $request->persistent = $data['persistent'] ?? false;
        $request->timeoutAfter = $data['timeoutAfter'] ?? null;
        $request->isRetryable = $data['isRetryable'] ?? false;

        return $request;
    }
}
