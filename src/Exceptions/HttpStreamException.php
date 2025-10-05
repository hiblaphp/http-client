<?php

namespace Hibla\Http\Exceptions;

use Hibla\Http\Interfaces\RequestExceptionInterface;

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
