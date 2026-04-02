<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Execution;

use Hibla\HttpClient\Interfaces\ResponseInterface;
use Hibla\Promise\Interfaces\PromiseInterface;

interface ExecutesRequestsInterface
{
    /**
     * Dispatch a GET request.
     *
     * @param  array<string, scalar|null> $query Query parameters appended to the URL.
     * @return PromiseInterface<ResponseInterface>
     */
    public function get(string $url, array $query = []): PromiseInterface;

    /**
     * Dispatch a POST request.
     *
     * When $data is non-empty and no body has been explicitly configured,
     * $data is JSON-encoded and Content-Type is set to application/json.
     *
     * @param  array<string, mixed> $data
     * @return PromiseInterface<ResponseInterface>
     */
    public function post(string $url, array $data = []): PromiseInterface;

    /**
     * Dispatch a PUT request.
     *
     * Applies the same automatic JSON encoding rule as post().
     *
     * @param  array<string, mixed> $data
     * @return PromiseInterface<ResponseInterface>
     */
    public function put(string $url, array $data = []): PromiseInterface;

    /**
     * Dispatch a DELETE request.
     *
     * @return PromiseInterface<ResponseInterface>
     */
    public function delete(string $url): PromiseInterface;

    /**
     * Dispatch a PATCH request.
     *
     * Applies the same automatic JSON encoding rule as post().
     *
     * @param  array<string, mixed> $data
     * @return PromiseInterface<ResponseInterface>
     */
    public function patch(string $url, array $data = []): PromiseInterface;

    /**
     * Dispatch an OPTIONS request.
     *
     * @return PromiseInterface<ResponseInterface>
     */
    public function options(string $url): PromiseInterface;

    /**
     * Dispatch a HEAD request.
     *
     * The response body will be empty per the HTTP specification.
     *
     * @return PromiseInterface<ResponseInterface>
     */
    public function head(string $url): PromiseInterface;

    /**
     * Dispatch a request with an arbitrary HTTP method.
     *
     * All shorthand methods delegate here after normalising their
     * arguments. Call this directly when you need a method not covered
     * by the shortcuts (e.g. PURGE, PROPFIND, REPORT).
     *
     * URI template parameters set via withUrlParameter() are expanded
     * before the request is dispatched.
     *
     * @return PromiseInterface<ResponseInterface>
     */
    public function send(string $method, string $url): PromiseInterface;
}
