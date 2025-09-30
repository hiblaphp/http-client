<?php

namespace Hibla\Http\Testing\Utilities;

class RecordedRequest
{
    public string $method;
    public string $url;
    public array $options;
    private array $parsedHeaders = [];
    private ?string $body = null;
    private ?array $parsedJson = null;

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
        if (!isset($this->options[CURLOPT_HTTPHEADER])) {
            return;
        }

        foreach ($this->options[CURLOPT_HTTPHEADER] as $header) {
            if (strpos($header, ':') !== false) {
                [$name, $value] = explode(':', $header, 2);
                $name = strtolower(trim($name));
                $value = trim($value);

                if (isset($this->parsedHeaders[$name])) {
                    // Handle multiple headers with same name
                    if (!is_array($this->parsedHeaders[$name])) {
                        $this->parsedHeaders[$name] = [$this->parsedHeaders[$name]];
                    }
                    $this->parsedHeaders[$name][] = $value;
                } else {
                    $this->parsedHeaders[$name] = $value;
                }
            }
        }
    }

    /**
     * Parse request body.
     */
    private function parseBody(): void
    {
        if (isset($this->options[CURLOPT_POSTFIELDS])) {
            $this->body = $this->options[CURLOPT_POSTFIELDS];

            // Try to parse as JSON
            if (is_string($this->body)) {
                $decoded = json_decode($this->body, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->parsedJson = $decoded;
                }
            }
        }
    }

    /**
     * Get all headers as associative array.
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
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Convert to array for debugging.
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