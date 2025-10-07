<?php

namespace Hibla\HttpClient\Exceptions;

use Exception;
use Hibla\HttpClient\Interfaces\RequestExceptionInterface;

class HttpException extends Exception implements RequestExceptionInterface
{
    protected ?string $url = null;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $url = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->url = $url;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }
}
