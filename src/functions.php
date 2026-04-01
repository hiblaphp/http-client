<?php

declare(strict_types=1);

namespace Hibla\HttpClient;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\HttpClient\Interfaces\EnhancedResponseInterface;

/**
 * Fetch data from URL (JavaScript-like fetch API).
 *
 * Provides a JavaScript-like fetch interface for making HTTP requests
 * with flexible options configuration.
 *
 * @param  string  $url  The URL to fetch from
 * @param  array<int|string, mixed>  $options  Request options (method, headers, body, etc.)
 * @return PromiseInterface<EnhancedResponseInterface> Promise that resolves with the response
 *
 * @example
 * $response = await(fetch('https://api.example.com', [
 *     'method' => 'POST',
 *     'headers' => ['Content-Type' => 'application/json'],
 *     'body' => json_encode(['key' => 'value'])
 * ]));
 */
function fetch(string $url, array $options = []): PromiseInterface
{
    return Http::fetch($url, $options);
}
