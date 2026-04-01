<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Handler;

use Hibla\HttpClient\Response;
use Hibla\HttpClient\ValueObjects\RetryConfig;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Contract for executing an HTTP request with automatic retry behaviour.
 *
 * Wraps a RequestExecutorHandlerInterface and re-issues the request
 * when transient failures occur, according to the rules in RetryConfig.
 *
 * Retryable conditions typically include:
 *   - NetworkException (connection failures, DNS errors, timeouts)
 *   - Configured retryable HTTP status codes (e.g. 429, 503)
 *
 * Non-retryable conditions (4xx client errors, invalid config) are
 * rejected immediately without consuming retry attempts.
 */
interface RetryHandlerInterface
{
    /**
     * Execute the request, retrying on transient failure up to the
     * limit defined in $retryConfig.
     *
     * The promise rejects with a NetworkException only after all
     * retry attempts have been exhausted.
     *
     * @param  string $url The fully resolved target URL.
     * @param  array<int|string, mixed> $options Transport-specific options.
     * @param  RetryConfig $retryConfig Policy governing retry attempts,
     * delays, and retryable conditions.
     * @return PromiseInterface<Response>
     */
    public function execute(string $url, array $options, RetryConfig $retryConfig): PromiseInterface;
}
