<?php

namespace Hibla\Http\Exceptions;

use Hibla\Http\Interfaces\RequestExceptionInterface;

/**
 * Generic request exception for errors that don't fit other categories.
 */
class RequestException extends HttpException implements RequestExceptionInterface
{
}