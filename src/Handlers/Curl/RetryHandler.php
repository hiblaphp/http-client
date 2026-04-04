<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Handlers\Curl;

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Interfaces\Cookie\CookieJarInterface;
use Hibla\HttpClient\Interfaces\Handler\RetryHandlerInterface;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\Traits\NormalizeHeaderTrait;
use Hibla\HttpClient\ValueObjects\RetryConfig;
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

        /** @var array<string> $tmpFiles */
        $tmpFiles = $curlOptions['_tmp_files'] ?? [];
        unset($curlOptions['_tmp_files']);

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
            $tmpFiles
        ) {
            $totalAttempts++;

            $requestId = Loop::addCurlRequest(
                $url,
                $curlOnlyOptions,
                function (?string $error, ?string $responseBody, ?int $httpCode, array $headers = [], ?string $httpVersion = null) use ($url, $retryConfig, $promise, &$attempt, &$totalAttempts, &$executeRequest, $cookieJar, &$timerId, $tmpFiles) {
                    if ($promise->isCancelled()) {
                        // Clean up temporary multipart files if cancelled mid-flight
                        foreach ($tmpFiles as $file) {
                            if (file_exists($file)) {
                                @unlink($file);
                            }
                        }

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

                    // We reached the final resolution (success or final failure). Clean up tmp files.
                    foreach ($tmpFiles as $file) {
                        if (file_exists($file)) {
                            @unlink($file);
                        }
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

                    if ($cookieJar instanceof CookieJarInterface) {
                        $responseObj->applyCookiesToJar($cookieJar);
                    }

                    $promise->resolve($responseObj);
                }
            );
        };

        $executeRequest();

        $promise->onCancel(function () use (&$requestId, &$timerId, $tmpFiles) {
            if ($requestId !== null) {
                Loop::cancelCurlRequest($requestId);
            }

            if ($timerId !== null) {
                Loop::cancelTimer($timerId);
            }

            // Clean up temporary multipart files on cancellation
            foreach ($tmpFiles as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
        });

        return $promise;
    }
}
