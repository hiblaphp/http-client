<?php

namespace Hibla\HttpClient\Handlers;

use Hibla\EventLoop\EventLoop;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Response;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Executes basic HTTP requests without any additional logic.
 * 
 * This is the base executor that other handlers can build upon.
 */
class RequestExecutor
{
    /**
     * Executes a basic HTTP request.
     *
     * @param string $url The target URL.
     * @param array<int|string, mixed> $curlOptions cURL options.
     * @return PromiseInterface<Response>
     */
    public function execute(string $url, array $curlOptions): PromiseInterface
    {
        /** @var CancellablePromise<Response> $promise */
        $promise = new CancellablePromise();

        $cookieJar = $curlOptions['_cookie_jar'] ?? null;
        unset($curlOptions['_cookie_jar']);

        /** @var array<int, mixed> $curlOnlyOptions */
        $curlOnlyOptions = array_filter($curlOptions, 'is_int', ARRAY_FILTER_USE_KEY);

        $requestId = EventLoop::getInstance()->addHttpRequest(
            $url,
            $curlOnlyOptions,
            function (?string $error, ?string $response, ?int $httpCode, array $headers = [], ?string $httpVersion = null) 
            use ($url, $promise, $cookieJar) {
                if ($promise->isCancelled()) {
                    return;
                }

                if ($error !== null) {
                    $promise->reject(new NetworkException(
                        "HTTP Request failed: {$error}",
                        0,
                        null,
                        $url,
                        $error
                    ));
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