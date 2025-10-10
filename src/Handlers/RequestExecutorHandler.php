<?php

namespace Hibla\HttpClient\Handlers;

use Hibla\EventLoop\EventLoop;
use Hibla\HttpClient\Exceptions\NetworkException;
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

        $requestId = EventLoop::getInstance()->addHttpRequest(
            $url,
            $curlOnlyOptions,
            function (?string $error, ?string $response, ?int $httpCode, array $headers = [], ?string $httpVersion = null) use ($url, $promise, $cookieJar) {
                if ($promise->isCancelled()) {
                    return;
                }

                if ($error !== null) {
                    $promise->reject(new NetworkException(
                        "HTTP Request failed in {$url}: {$error}",
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
}
