<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces;

use Hibla\HttpClient\Response;
use Hibla\HttpClient\RetryConfig;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Interface for handling HTTP requests with automatic retry logic.
 *
 * Implementations should wrap HTTP requests and automatically retry them
 * based on configurable retry policies when transient failures occur.
 */
interface RetryHandlerInterface
{
    /**
     * Executes an HTTP request with retry logic.
     *
     * The handler will automatically retry the request based on the provided
     * RetryConfig when encountering retryable errors or status codes.
     *
     * @param string $url The target URL for the HTTP request
     * @param array<int|string, mixed> $options Request configuration options.
     *                                           Implementations may accept various options such as:
     *                                           - HTTP method, headers, body
     *                                           - Timeout and connection settings
     *                                           - Authentication and SSL configuration
     *                                           - Cookie jar for cookie handling
     *                                           - Custom implementation-specific options
     * @param RetryConfig $retryConfig Configuration for retry behavior including:
     *                                  - Maximum number of retry attempts
     *                                  - Retryable status codes
     *                                  - Retryable error patterns
     *                                  - Delay calculation strategy (fixed, exponential backoff, etc.)
     *
     * @return PromiseInterface<Response> A promise that resolves to a Response on success
     *                                     or rejects with NetworkException after all retry
     *                                     attempts have been exhausted
     */
    public function execute(string $url, array $options, RetryConfig $retryConfig): PromiseInterface;
}
