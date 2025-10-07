<?php

namespace Hibla\HttpClient\Testing\Utilities;

class RecordedRequest
{
    public string $method;
    public string $url;

    /**
     * @var array<int, mixed>
     */
    public array $options;

    /**
     * @var array<string, string|array<int, string>>
     */
    private array $parsedHeaders = [];

    private ?string $body = null;

    /**
     * @var array<mixed>|null
     */
    private ?array $parsedJson = null;

    /**
     * @param array<int, mixed> $options
     */
    public function __construct(string $method, string $url, array $options)
    {
        $this->method = strtoupper($method);
        $this->url = $url;
        $this->options = $options;
        $this->parseHeaders();
        $this->parseBody();
    }

    /**
     * Parse cURL and fetch-style headers into a single associative array.
     */
    private function parseHeaders(): void
    {
        if (isset($this->options[CURLOPT_HTTPHEADER]) && is_array($this->options[CURLOPT_HTTPHEADER])) {
            foreach ($this->options[CURLOPT_HTTPHEADER] as $header) {
                if (! is_string($header) || strpos($header, ':') === false) {
                    continue;
                }

                [$name, $value] = explode(':', $header, 2);
                $name = strtolower(trim($name));
                $value = trim($value);

                if (isset($this->parsedHeaders[$name])) {
                    $existing = $this->parsedHeaders[$name];
                    if (! is_array($existing)) {
                        $this->parsedHeaders[$name] = [$existing];
                    }
                    if (is_array($this->parsedHeaders[$name])) {
                        $this->parsedHeaders[$name][] = $value;
                    }
                } else {
                    $this->parsedHeaders[$name] = $value;
                }
            }
        }

        if (isset($this->options['headers']) && is_array($this->options['headers'])) {
            foreach ($this->options['headers'] as $name => $value) {
                if (is_string($name) && is_scalar($value)) {
                    $normalizedName = strtolower(trim($name));
                    $this->parsedHeaders[$normalizedName] = trim((string)$value);
                }
            }
        }
    }

    /**
     * Parse request body.
     */
    private function parseBody(): void
    {
        if (isset($this->options[CURLOPT_POSTFIELDS]) && is_string($this->options[CURLOPT_POSTFIELDS])) {
            $this->body = $this->options[CURLOPT_POSTFIELDS];
        } 
        elseif (isset($this->options['body']) && is_string($this->options['body'])) {
            $this->body = $this->options['body'];
        }

        if ($this->body !== null) {
            $decoded = json_decode($this->body, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->parsedJson = $decoded;
            }
        }
    }

    /**
     * Get all headers as associative array.
     *
     * @return array<string, string|array<int, string>>
     */
    public function getHeaders(): array
    {
        return $this->parsedHeaders;
    }

    /**
     * Check if a header exists (case-insensitive).
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->parsedHeaders[strtolower($name)]);
    }

    /**
     * Get a specific header value (case-insensitive).
     *
     * @return string|array<int, string>|null
     */
    public function getHeader(string $name): string|array|null
    {
        return $this->parsedHeaders[strtolower($name)] ?? null;
    }

    /**
     * Get header as string (joins array values with comma).
     */
    public function getHeaderLine(string $name): ?string
    {
        $value = $this->getHeader($name);

        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return implode(', ', $value);
        }

        return $value;
    }

    /**
     * Get request body.
     */
    public function getBody(): ?string
    {
        return $this->body;
    }

    /**
     * Get parsed JSON body.
     *
     * @return array<mixed>|null
     */
    public function getJson(): ?array
    {
        return $this->parsedJson;
    }

    /**
     * Check if body is JSON.
     */
    public function isJson(): bool
    {
        return $this->parsedJson !== null;
    }

    /**
     * Get request method.
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Get request URL.
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Get raw cURL options.
     *
     * @return array<int, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Convert to array for debugging.
     *
     * @return array{method: string, url: string, headers: array<string, string|array<int, string>>, body: string|null, json: array<mixed>|null}
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'url' => $this->url,
            'headers' => $this->parsedHeaders,
            'body' => $this->body,
            'json' => $this->parsedJson,
        ];
    }
}