<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Handlers;

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Interfaces\RetryHandlerInterface;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\RetryConfig;
use Hibla\HttpClient\Traits\NormalizeHeaderTrait;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

class RetryHandler implements RetryHandlerInterface
{
    use NormalizeHeaderTrait;

    /**
     * {@inheritDoc}
     */
    public function execute(string $url, array $curlOptions, RetryConfig $retryConfig): PromiseInterface
    {
        /** @var Promise<Response> $promise */
        $promise = new Promise();
        $attempt = 0;
        $totalAttempts = 0;
        /** @var string|null $requestId */
        $requestId = null;
        /** @var string|null $timerId */
        $timerId = null;

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
            &$timerId,
        ) {
            $totalAttempts++;

            $requestId = Loop::addHttpRequest(
                $url,
                $curlOnlyOptions,
                function (?string $error, ?string $responseBody, ?int $httpCode, array $headers = [], ?string $httpVersion = null) use ($url, $retryConfig, $promise, &$attempt, &$totalAttempts, &$executeRequest, $cookieJar, &$timerId) {
                    if ($promise->isCancelled()) {
                        return;
                    }

                    // Cancel any pending timer if we're about to resolve/reject
                    if ($timerId !== null) {
                        Loop::cancelTimer($timerId);
                        $timerId = null;
                    }

                    $isRetryable = ($error !== null && $retryConfig->isRetryableError($error)) ||
                        ($httpCode !== null && \in_array($httpCode, $retryConfig->retryableStatusCodes, true));

                    if ($isRetryable && $attempt < $retryConfig->maxRetries) {
                        $attempt++;
                        $delay = $retryConfig->getDelay($attempt);

                        $timerId = Loop::addTimer($delay, function () use ($executeRequest, &$timerId) {
                            $timerId = null;
                            $executeRequest();
                        });

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

        $promise->onCancel(function () use (&$requestId, &$timerId) {
            if ($requestId !== null) {
                Loop::cancelHttpRequest($requestId);
            }

            if ($timerId !== null) {
                Loop::cancelTimer($timerId);
            }
        });

        return $promise;
    }
}
