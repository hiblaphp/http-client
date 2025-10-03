<?php

namespace Hibla\Http\Exceptions;

use Hibla\Http\Interfaces\ServerExceptionInterface;

/**
 * Thrown for server-side HTTP errors (5xx status codes).
 */
class ServerException extends HttpException implements ServerExceptionInterface
{
    private ?int $statusCode = null;
    private array $responseHeaders = [];

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

    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    public function setResponseHeaders(array $headers): void
    {
        $this->responseHeaders = $headers;
    }
}