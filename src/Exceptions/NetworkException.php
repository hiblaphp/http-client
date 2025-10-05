<?php

namespace Hibla\Http\Exceptions;

use Hibla\Http\Interfaces\NetworkExceptionInterface;

/**
 * Thrown when network-level errors occur.
 * Examples: connection timeout, DNS resolution failure, connection refused.
 */
class NetworkException extends HttpException implements NetworkExceptionInterface
{
    private ?string $errorType = null;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $url = null,
        ?string $errorType = null
    ) {
        parent::__construct($message, $code, $previous, $url);
        $this->errorType = $errorType;
    }

    public function getErrorType(): ?string
    {
        return $this->errorType;
    }

    public function setErrorType(string $errorType): void
    {
        $this->errorType = $errorType;
    }
}
