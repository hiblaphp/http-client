<?php

namespace Hibla\HttpClient\Exceptions;

use Hibla\HttpClient\Interfaces\RequestExceptionInterface;

/**
 * Generic request exception for errors that don't fit other categories.
 */
class RequestException extends HttpException implements RequestExceptionInterface
{
}
