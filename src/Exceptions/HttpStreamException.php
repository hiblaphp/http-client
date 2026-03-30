<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Exceptions;

use Hibla\HttpClient\Interfaces\Exception\RequestExceptionInterface;

/**
 * Thrown when streaming-specific errors occur.
 */
class HttpStreamException extends HttpException implements RequestExceptionInterface
{
    private ?string $streamState = null;

    public function setStreamState(string $state): void
    {
        $this->streamState = $state;
    }

    public function getStreamState(): ?string
    {
        return $this->streamState;
    }
}
