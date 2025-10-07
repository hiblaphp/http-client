<?php

namespace Hibla\HttpClient\Exceptions;

use Hibla\HttpClient\Interfaces\ServerExceptionInterface;

/**
 * Thrown for server-side HTTP errors (5xx status codes).
 */
class ServerException extends HttpException implements ServerExceptionInterface
{
    private ?int $statusCode = null;

    /**
     * @var array<string, mixed> The response headers, e.g., ['Content-Type' => ['application/json']]
     */
    private array $responseHeaders = [];

    /**
     * @param array<string, mixed> $responseHeaders
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $url = null,
        ?int $statusCode = null,
        array $responseHeaders = []
    ) {
        parent::__construct($message, $code, $previous, $url);
        $this->statusCode = $statusCode;
        $this->responseHeaders = $responseHeaders;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function setStatusCode(int $statusCode): void
    {
        $this->statusCode = $statusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    /**
     * @param array<string, mixed> $headers
     */
    public function setResponseHeaders(array $headers): void
    {
        $this->responseHeaders = $headers;
    }
}
