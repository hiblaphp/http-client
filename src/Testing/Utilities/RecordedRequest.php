<?php

namespace Hibla\Http\Testing\Utilities;

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
     * Parse cURL headers into associative array.
     */
    private function parseHeaders(): void
    {
        if (! isset($this->options[CURLOPT_HTTPHEADER])) {
            return;
        }

        $headers = $this->options[CURLOPT_HTTPHEADER];
        if (! is_array($headers)) {
            return;
        }

        foreach ($headers as $header) {
            if (! is_string($header) || strpos($header, ':') === false) {
                continue;
            }

            [$name, $value] = explode(':', $header, 2);
            $name = strtolower(trim($name));
            $value = trim($value);

            if (isset($this->parsedHeaders[$name])) {
                // Handle multiple headers with same name
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

    /**
     * Parse request body.
     */
    private function parseBody(): void
    {
        if (! isset($this->options[CURLOPT_POSTFIELDS])) {
            return;
        }

        $postFields = $this->options[CURLOPT_POSTFIELDS];

        if (! is_string($postFields)) {
            return;
        }

        $this->body = $postFields;

        // Try to parse as JSON
        $decoded = json_decode($this->body, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $this->parsedJson = $decoded;
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
