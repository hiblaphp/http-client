<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Handlers;

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\RetryConfig;
use Hibla\HttpClient\Traits\NormalizeHeaderTrait;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

/**
 * Handles HTTP requests with automatic retry logic.
 *
 * This handler wraps HTTP requests and automatically retries them based on
 * configurable retry policies when transient failures occur.
 */
class RetryHandler
{
    use NormalizeHeaderTrait;

    /**
     * Executes an HTTP request with retry logic.
     *
     * @param string $url The target URL.
     * @param array<int|string, mixed> $curlOptions cURL options.
     * @param RetryConfig $retryConfig Retry configuration.
     * @return PromiseInterface<Response>
     */
    public function execute(string $url, array $curlOptions, RetryConfig $retryConfig): PromiseInterface
    {
        /** @var Promise<Response> $promise */
        $promise = new Promise();
        $attempt = 0;
        $totalAttempts = 0;
        /** @var string|null $requestId */
        $requestId = null;

        $cookieJar = $curlOptions['_cookie_jar'] ?? null;
        unset($curlOptions['_cookie_jar']);

        /** @var array<int, mixed> $curlOnlyOptions */
        $curlOnlyOptions = array_filter($curlOptions, 'is_int', ARRAY_FILTER_USE_KEY);

        $executeRequest = function () use (
            $url,
            $curlOnlyOptions,
            $retryConfig,
            $promise,
            &$attempt,
            &$totalAttempts,
            &$requestId,
            &$executeRequest,
            $cookieJar,
        ) {
            $totalAttempts++;

            $requestId = Loop::addHttpRequest(
                $url,
                $curlOnlyOptions,
                function (?string $error, ?string $responseBody, ?int $httpCode, array $headers = [], ?string $httpVersion = null) use ($url, $retryConfig, $promise, &$attempt, &$totalAttempts, &$executeRequest, $cookieJar) {
                    if ($promise->isCancelled()) {
                        return;
                    }

                    $isRetryable = ($error !== null && $retryConfig->isRetryableError($error)) ||
                        ($httpCode !== null && in_array($httpCode, $retryConfig->retryableStatusCodes, true));

                    if ($isRetryable && $attempt < $retryConfig->maxRetries) {
                        $attempt++;
                        $delay = $retryConfig->getDelay($attempt);
                        Loop::addTimer($delay, $executeRequest);

                        return;
                    }

                    if ($error !== null) {
                        $promise->reject(new NetworkException(
                            "HTTP Request failed after {$totalAttempts} attempts in {$url}: {$error}",
                            0,
                            null,
                            $url,
                            $error
                        ));

                        return;
                    }

                    /** @var array<string, array<string>|string> $normalizedHeaders */
                    $normalizedHeaders = $this->normalizeHeaders($headers);
                    $responseObj = new Response($responseBody ?? '', $httpCode ?? 0, $normalizedHeaders);

                    if ($httpVersion !== null) {
                        $responseObj->setHttpVersion($httpVersion);
                    }

                    if ($cookieJar instanceof \Hibla\HttpClient\Interfaces\CookieJarInterface) {
                        $responseObj->applyCookiesToJar($cookieJar);
                    }

                    $promise->resolve($responseObj);
                }
            );
        };

        $executeRequest();

        $promise->onCancel(function () use (&$requestId) {
            if ($requestId !== null) {
                Loop::cancelHttpRequest($requestId);
            }
        });

        return $promise;
    }
}
