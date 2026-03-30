<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Execution;

use Hibla\HttpClient\Response;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Contract for dispatching HTTP requests.
 *
 * This interface represents the execution surface of the fluent builder —
 * the point at which configuration is finalised and a network call is made.
 *
 * Each method returns a promise so requests can be composed and awaited
 * in an async context without blocking the event loop.
 *
 * The shorthand methods (get, post, put, etc.) are convenience wrappers
 * around send(). When body data is passed directly to post(), put(), or
 * patch() and no body has been explicitly set, the data is JSON-encoded
 * automatically.
 */
interface ExecutesRequestsInterface
{
    /**
     * Dispatch a GET request.
     *
     * @param  array<string, scalar|null> $query Query parameters appended to the URL.
     * @return PromiseInterface<Response>
     */
    public function get(string $url, array $query = []): PromiseInterface;

    /**
     * Dispatch a POST request.
     *
     * When $data is non-empty and no body has been explicitly configured,
     * $data is JSON-encoded and Content-Type is set to application/json.
     *
     * @param  array<string, mixed> $data
     * @return PromiseInterface<Response>
     */
    public function post(string $url, array $data = []): PromiseInterface;

    /**
     * Dispatch a PUT request.
     *
     * Applies the same automatic JSON encoding rule as post().
     *
     * @param  array<string, mixed> $data
     * @return PromiseInterface<Response>
     */
    public function put(string $url, array $data = []): PromiseInterface;

    /**
     * Dispatch a DELETE request.
     *
     * @return PromiseInterface<Response>
     */
    public function delete(string $url): PromiseInterface;

    /**
     * Dispatch a PATCH request.
     *
     * Applies the same automatic JSON encoding rule as post().
     *
     * @param  array<string, mixed> $data
     * @return PromiseInterface<Response>
     */
    public function patch(string $url, array $data = []): PromiseInterface;

    /**
     * Dispatch an OPTIONS request.
     *
     * @return PromiseInterface<Response>
     */
    public function options(string $url): PromiseInterface;

    /**
     * Dispatch a HEAD request.
     *
     * The response body will be empty per the HTTP specification.
     *
     * @return PromiseInterface<Response>
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
     * @return PromiseInterface<Response>
     */
    public function send(string $method, string $url): PromiseInterface;
}
