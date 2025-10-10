<?php

namespace Hibla\HttpClient\Handlers;

use Hibla\EventLoop\EventLoop;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Exceptions\TimeoutException;
use Hibla\HttpClient\Response;
use Hibla\HttpClient\Traits\NormalizeHeaderTrait;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;

/**
 * Executes basic HTTP requests without any additional logic.
 *
 * This is the base executor that other handlers can build upon.
 */
class RequestExecutorHandler
{
    use NormalizeHeaderTrait;

    /**
     * Executes a basic HTTP request.
     *
     * @param string $url The target URL.
     * @param array<int|string, mixed> $curlOptions cURL options.
     * @return CancellablePromiseInterface<Response>
     */
    public function execute(string $url, array $curlOptions): CancellablePromiseInterface
    {
        /** @var CancellablePromise<Response> $promise */
        $promise = new CancellablePromise();

        $cookieJar = $curlOptions['_cookie_jar'] ?? null;
        unset($curlOptions['_cookie_jar']);

        /** @var array<int, mixed> $curlOnlyOptions */
        $curlOnlyOptions = array_filter($curlOptions, 'is_int', ARRAY_FILTER_USE_KEY);

        $timeout = $curlOptions[CURLOPT_TIMEOUT] ?? $curlOptions[CURLOPT_TIMEOUT_MS] ?? null;
        $connectTimeout = $curlOptions[CURLOPT_CONNECTTIMEOUT] ?? $curlOptions[CURLOPT_CONNECTTIMEOUT_MS] ?? null;

        $requestId = EventLoop::getInstance()->addHttpRequest(
            $url,
            $curlOnlyOptions,
            function (?string $error, ?string $response, ?int $httpCode, array $headers = [], ?string $httpVersion = null) use ($url, $promise, $cookieJar, $timeout, $connectTimeout) {
                if ($promise->isCancelled()) {
                    return;
                }

                if ($error !== null) {
                    $exception = $this->createExceptionFromError($error, $url, $timeout, $connectTimeout);
                    $promise->reject($exception);
                } else {
                    $normalizedHeaders = $this->normalizeHeaders($headers);
                    $responseObj = new Response($response ?? '', $httpCode ?? 0, $normalizedHeaders);

                    if ($httpVersion !== null) {
                        $responseObj->setHttpVersion($httpVersion);
                    }

                    if ($cookieJar instanceof \Hibla\HttpClient\Interfaces\CookieJarInterface) {
                        $responseObj->applyCookiesToJar($cookieJar);
                    }

                    $promise->resolve($responseObj);
                }
            }
        );

        $promise->setCancelHandler(function () use ($requestId) {
            EventLoop::getInstance()->cancelHttpRequest($requestId);
        });

        return $promise;
    }

    /**
     * Create appropriate exception based on error message.
     *
     * @param string $error The error message from cURL
     * @param string $url The request URL
     * @param int|float|null $timeout The operation timeout value
     * @param int|float|null $connectTimeout The connection timeout value
     * @return NetworkException|TimeoutException
     */
    private function createExceptionFromError(
        string $error,
        string $url,
        $timeout = null,
        $connectTimeout = null
    ): NetworkException {
        $errorLower = strtolower($error);

        if (
            str_contains($errorLower, 'timed out') ||
            str_contains($errorLower, 'timeout') ||
            str_contains($errorLower, 'operation timed out')
        ) {
            $timeoutType = 'operation';
            $timeoutValue = $timeout;

            if (str_contains($errorLower, 'connection') || str_contains($errorLower, 'connect')) {
                $timeoutType = 'connection';
                $timeoutValue = $connectTimeout ?? $timeout;
            }

            if ($timeoutValue !== null && $timeoutValue > 1000) {
                $timeoutValue = $timeoutValue / 1000;
            }

            return new TimeoutException(
                "Request to {$url} timed out: {$error}",
                0,
                null,
                $url,
                'timeout',
                $timeoutValue !== null ? (float) $timeoutValue : null,
                $timeoutType
            );
        }

        return new NetworkException(
            "HTTP Request failed for {$url}: {$error}",
            0,
            null,
            $url,
            $this->detectErrorType($error)
        );
    }

    /**
     * Detect error type from error message.
     */
    private function detectErrorType(string $error): string
    {
        $errorLower = strtolower($error);

        if (str_contains($errorLower, 'connection refused')) {
            return 'connection_refused';
        }

        if (str_contains($errorLower, 'could not resolve') || str_contains($errorLower, 'dns')) {
            return 'dns';
        }

        if (str_contains($errorLower, 'ssl') || str_contains($errorLower, 'certificate')) {
            return 'ssl';
        }

        if (str_contains($errorLower, 'network is unreachable')) {
            return 'network_unreachable';
        }

        return 'unknown';
    }
}