<?php

namespace Hibla\Http;

use Hibla\Http\Http;
use Hibla\Http\Request;
use Hibla\Http\Response;
use Hibla\Http\StreamingResponse;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Get HTTP request builder instance.
 *
 * Returns a new HTTP request builder that can be used to configure
 * and execute HTTP requests with method chaining.
 *
 * @return Request HTTP request builder instance
 *
 * @example
 * $response = await(http()->get('https://api.example.com'));
 */
function http(): Request
{
    return Http::request();
}


/**
 * Perform an asynchronous GET request.
 *
 * Sends a GET request to the specified URL with optional query parameters
 * without blocking the event loop.
 *
 * @param  string  $url  The URL to send the request to
 * @param  array<string, mixed>  $query  Optional query parameters
 * @return PromiseInterface<Response> Promise that resolves with the response
 *
 * @example
 * $response = await(http_get('https://api.example.com', ['key' => 'value']));
 */
function http_get(string $url, array $query = []): PromiseInterface
{
    return Http::get($url, $query);
}

/**
 * Perform an asynchronous POST request.
 *
 * Sends a POST request to the specified URL with optional data payload
 * without blocking the event loop.
 *
 * @param  string  $url  The URL to send the request to
 * @param  array<string, mixed>  $data  Optional data payload
 * @return PromiseInterface<Response> Promise that resolves with the response
 *
 * @example
 * $response = await(http_post('https://api.example.com', ['name' => 'John']));
 */
function http_post(string $url, array $data = []): PromiseInterface
{
    return Http::post($url, $data);
}

/**
 * Stream an HTTP request.
 *
 * Performs an HTTP request and processes the response as a stream,
 * calling the provided callback for each chunk of data received.
 *
 * @param  string  $url  The URL to stream from
 * @param  array<int|string, mixed>  $options  Request options
 * @param  callable|null  $onChunk  Callback to handle each chunk
 * @return CancellablePromiseInterface<StreamingResponse> Promise that resolves when streaming completes
 *
 * @example
 * await(http_stream('https://api.example.com/data', [], function($chunk) {
 *     echo "Received: " . $chunk;
 * }));
 */
function http_stream(string $url, ?callable $onChunk = null): CancellablePromiseInterface
{
    /** @var CancellablePromiseInterface<StreamingResponse> */
    return Http::stream($url,  $onChunk);
}


/**
 * Download a file from a URL.
 *
 * Downloads a file from the specified URL and saves it to the destination
 * path without blocking the event loop.
 *
 * @param  string  $url  The URL to download from
 * @param  string  $destination  The local path to save the file
 * @return CancellablePromiseInterface<array{file: string, status: int, headers: array<mixed>}> Promise that resolves when download completes
 *
 * @example
 * await(http_download('https://example.com/file.zip', '/local/file.zip'));
 */
function http_download(string $url, string $destination): CancellablePromiseInterface
{
    return Http::download($url, $destination);
}


/**
 * Perform an asynchronous PUT request.
 *
 * Sends a PUT request to the specified URL with optional data payload
 * without blocking the event loop.
 *
 * @param  string  $url  The URL to send the request to
 * @param  array<string, mixed>  $data  Optional data payload
 * @return PromiseInterface<Response> Promise that resolves with the response
 *
 * @example
 * $response = await(http_put('https://api.example.com/resource/1', ['name' => 'Updated']));
 */
function http_put(string $url, array $data = []): PromiseInterface
{
    return Http::put($url, $data);
}

/**
 * Perform an asynchronous DELETE request.
 *
 * Sends a DELETE request to the specified URL without blocking the event loop.
 *
 * @param  string  $url  The URL to send the request to
 * @return PromiseInterface<Response> Promise that resolves with the response
 *
 * @example
 * $response = await(http_delete('https://api.example.com/resource/1'));
 */
function http_delete(string $url): PromiseInterface
{
    return Http::delete($url);
}

/**
 * Fetch data from URL (JavaScript-like fetch API).
 *
 * Provides a JavaScript-like fetch interface for making HTTP requests
 * with flexible options configuration.
 *
 * @param  string  $url  The URL to fetch from
 * @param  array<int|string, mixed>  $options  Request options (method, headers, body, etc.)
 * @return PromiseInterface<Response> Promise that resolves with the response
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
