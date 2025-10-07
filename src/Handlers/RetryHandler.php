<?php

namespace Hibla\HttpClient\Handlers;

use Hibla\EventLoop\EventLoop;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\RetryConfig;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Handles HTTP requests with automatic retry logic.
 * 
 * This handler wraps HTTP requests and automatically retries them based on
 * configurable retry policies when transient failures occur.
 */
class RetryHandler
{
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
        /** @var CancellablePromise<Response> $promise */
        $promise = new CancellablePromise();
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
            $cookieJar
        ) {
            $totalAttempts++;

            $requestId = EventLoop::getInstance()->addHttpRequest(
                $url,
                $curlOnlyOptions,
                function (?string $error, ?string $responseBody, ?int $httpCode, array $headers = [], ?string $httpVersion = null) 
                use ($url, $retryConfig, $promise, &$attempt, &$totalAttempts, &$executeRequest, $cookieJar) {
                    if ($promise->isCancelled()) {
                        return;
                    }

                    $isRetryable = ($error !== null && $retryConfig->isRetryableError($error)) ||
                        ($httpCode !== null && in_array($httpCode, $retryConfig->retryableStatusCodes, true));

                    if ($isRetryable && $attempt < $retryConfig->maxRetries) {
                        $attempt++;
                        $delay = $retryConfig->getDelay($attempt);
                        EventLoop::getInstance()->addTimer($delay, $executeRequest);
                        return;
                    }

                    if ($error !== null) {
                        $promise->reject(new NetworkException(
                            "HTTP Request failed after {$totalAttempts} attempts: {$error}",
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

        $promise->setCancelHandler(function () use (&$requestId) {
            if ($requestId !== null) {
                EventLoop::getInstance()->cancelHttpRequest($requestId);
            }
        });

        return $promise;
    }

    /**
     * Normalizes headers array to the expected format.
     *
     * @param array<mixed> $headers The headers to normalize.
     * @return array<string, array<string>|string> Normalized headers.
     */
    private function normalizeHeaders(array $headers): array
    {
        /** @var array<string, array<string>|string> $normalized */
        $normalized = [];

        foreach ($headers as $key => $value) {
            if (is_string($key)) {
                if (is_string($value)) {
                    $normalized[$key] = $value;
                } elseif (is_array($value)) {
                    $stringValues = array_filter($value, 'is_string');
                    if (count($stringValues) > 0) {
                        $normalized[$key] = array_values($stringValues);
                    }
                }
            }
        }

        return $normalized;
    }
}