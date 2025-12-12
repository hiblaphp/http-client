<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces;

use Hibla\HttpClient\Response;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Interface for executing basic HTTP requests without additional logic.
 *
 * This is the base executor interface that other handlers can build upon.
 * Implementations should handle the fundamental HTTP request execution,
 * error handling, and response creation.
 */
interface RequestExecutorHandlerInterface
{
    /**
     * Executes a basic HTTP request.
     *
     * This method performs a single HTTP request with the provided options
     * and returns a promise that resolves to a Response object. It handles:
     * - Async request execution through the event loop
     * - Cookie jar integration for cookie management
     * - HTTP version detection and tracking
     * - Request cancellation support
     * - Appropriate exception creation based on error types
     *
     * Error handling categorizes failures into specific types:
     * - TimeoutException: For operation or connection timeout errors
     * - NetworkException: For other network-related failures including:
     *   * connection_refused: Server actively refused connection
     *   * dns: DNS resolution failures
     *   * ssl: SSL/TLS certificate or handshake errors
     *   * network_unreachable: Network routing issues
     *   * unknown: Other network errors
     *
     * @param string $url The target URL for the HTTP request
     * @param array<int|string, mixed> $options Request configuration options.
     *                                           Implementations may accept various options such as:
     *                                           - HTTP method, headers, body
     *                                           - Timeout values (operation and connection)
     *                                           - SSL/TLS verification settings
     *                                           - Redirect behavior
     *                                           - Authentication credentials
     *                                           - Cookie jar for cookie handling
     *                                           - Proxy configuration
     *                                           - Custom implementation-specific options
     *
     * @return PromiseInterface<Response> A promise that resolves to a Response object containing:
     *                                     - Response body (string)
     *                                     - HTTP status code (int)
     *                                     - Response headers (normalized array)
     *                                     - HTTP protocol version (if available)
     *                                     - Cookies applied to jar (if provided)
     *
     *                                     The promise rejects with:
     *                                     - TimeoutException: On timeout errors
     *                                     - NetworkException: On other network failures
     */
    public function execute(string $url, array $options): PromiseInterface;
}
