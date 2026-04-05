<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Handlers\Curl;

use Hibla\EventLoop\Loop;
use Hibla\HttpClient\Exceptions\HttpStreamException;
use Hibla\HttpClient\Exceptions\NetworkException;
use Hibla\HttpClient\Interfaces\Cookie\CookieJarInterface;
use Hibla\HttpClient\Interfaces\Handler\StreamingHandlerInterface;
use Hibla\HttpClient\Stream;
use Hibla\HttpClient\StreamingResponse;
use Hibla\HttpClient\Traits\NormalizeHeaderTrait;
use Hibla\HttpClient\Uri;
use Hibla\HttpClient\ValueObjects\Cookie;
use Hibla\HttpClient\ValueObjects\DownloadProgress;
use Hibla\HttpClient\ValueObjects\UploadProgress;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

class StreamingHandler implements StreamingHandlerInterface
{
    use NormalizeHeaderTrait;

    /**
     * @inheritDoc
     */
    public function streamRequest(
        string $url,
        array $options,
        ?callable $onChunk = null
    ): PromiseInterface {
        /** @var Promise<StreamingResponse> $promise */
        $promise = new Promise();

        $stream = new Stream();

        /** @var StreamingResponse|null $streamingResponse Reference to the response object to link the request ID */
        $streamingResponse = null;

        /** @var string|null $requestId Reference to the curl request ID to pass into the closure */
        $requestId = null;

        /** @var array<string> $tmpFiles */
        $tmpFiles = $options['_tmp_files'] ?? [];
        unset($options['_tmp_files']);

        $cookieJar = $options['_cookie_jar'] ?? null;
        unset($options['_cookie_jar']);

        $curlOnlyOptions = array_filter($options, 'is_int', ARRAY_FILTER_USE_KEY);

        $headersProcessed = false;
        $rawHeaders = [];

        $streamingOptions = array_replace($curlOnlyOptions, [
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use ($stream, $onChunk): int {
                $stream->getHandler()->writeToBuffer($data);

                if ($onChunk !== null) {
                    $onChunk($data);
                }

                return \strlen($data);
            },
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use ($url, &$headersProcessed, &$rawHeaders, $promise, $stream, &$streamingResponse, &$requestId, $cookieJar) {
                $trimmed = trim($header);
                if ($trimmed !== '') {
                    $rawHeaders[] = $header;
                }

                if (! $headersProcessed && $trimmed === '') {
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                    if ($httpCode > 0) {
                        $parsedHeaders = $this->parseRawHeaders($rawHeaders);

                        $streamingResponse = new StreamingResponse($stream, $httpCode, $parsedHeaders);

                        if ($requestId !== null) {
                            $streamingResponse->setRequestId($requestId);
                        }

                        // Persist any Set-Cookie headers from the streaming response into
                        // the jar so subsequent requests on the same jar replay them correctly.
                        if ($cookieJar instanceof CookieJarInterface) {
                            $originHost = (new Uri($url))->getHost();
                            $setCookieValues = $parsedHeaders['set-cookie'] ?? [];
                            if (\is_string($setCookieValues)) {
                                $setCookieValues = [$setCookieValues];
                            }
                            foreach ($setCookieValues as $setCookie) {
                                $cookie = Cookie::fromSetCookieHeader($setCookie, $originHost ?: null);
                                if ($cookie !== null) {
                                    $cookieJar->setCookie($cookie);
                                }
                            }
                        }

                        $promise->resolve($streamingResponse);
                        $headersProcessed = true;
                    }
                }

                return \strlen($header);
            },
        ]);

        $requestId = Loop::addCurlRequest(
            $url,
            $streamingOptions,
            function (?string $error) use ($url, $promise, $stream, $tmpFiles): void {

                foreach ($tmpFiles as $file) {
                    if (file_exists($file)) {
                        @unlink($file);
                    }
                }

                if ($error !== null) {
                    if (! $promise->isSettled()) {
                        $promise->reject(new NetworkException("Streaming failed: $error", 0, null, $url, $error));
                    }
                    $stream->close();
                } else {
                    $stream->getHandler()->markEof();
                }
            }
        );

        if ($streamingResponse !== null) {
            $streamingResponse->setRequestId($requestId);
        }

        $promise->onCancel(function () use (&$requestId, $stream, $tmpFiles): void {
            if ($requestId !== null) {
                Loop::cancelCurlRequest($requestId);
            }
            $stream->close();

            foreach ($tmpFiles as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
        });

        return $promise;
    }

    /**
     * @inheritDoc
     */
    public function downloadFile(
        string $url,
        string $destination,
        array $options = [],
        ?callable $onProgress = null,
    ): PromiseInterface {
        /** @var Promise<array{file: string, status: int, headers: array<mixed>, protocol_version: string|null, size: int|false}> $promise */
        $promise = new Promise();

        // Extract the cookie jar before array_filter strips string keys — without this
        // the jar reference is silently discarded and response cookies are never stored.
        $cookieJar = $options['_cookie_jar'] ?? null;
        unset($options['_cookie_jar']);

        $file = fopen($destination, 'wb');
        if ($file === false) {
            $exception = new HttpStreamException("Cannot open file for writing: {$destination}", 0, null, $url);
            $exception->setStreamState('file_open_failed');
            $promise->reject($exception);

            return $promise;
        }

        $curlOnlyOptions = array_filter($options, 'is_int', ARRAY_FILTER_USE_KEY);

        $downloadOptions = array_replace($curlOnlyOptions, [
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use ($file): int|false {
                return fwrite($file, $data);
            },
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function (
                $ch,
                int $downloadTotal,
                int $downloaded,
                int $uploadTotal,
                int $uploaded,
            ) use ($promise, $onProgress): int {
                if ($promise->isCancelled()) {
                    return 1;
                }

                if ($onProgress !== null && $downloadTotal > 0) {
                    $onProgress(new DownloadProgress($downloadTotal, $downloaded));
                }

                return 0;
            },
        ]);

        $requestId = Loop::addCurlRequest(
            $url,
            $downloadOptions,
            function (?string $error, $response, ?int $httpCode, array $headers = [], ?string $httpVersion = null) use ($url, $promise, $file, $destination, $cookieJar): void {
                fclose($file);

                if ($promise->isCancelled()) {
                    if (file_exists($destination)) {
                        unlink($destination);
                    }

                    return;
                }

                if ($error !== null) {
                    if (file_exists($destination)) {
                        unlink($destination);
                    }

                    $promise->reject(new NetworkException(
                        "Download failed: {$error}",
                        0,
                        null,
                        $url,
                        $error
                    ));
                } else {
                    // CurlRequest normalizes captured header names to lowercase, so
                    // Set-Cookie arrives as 'set-cookie'. The value may be a plain string
                    // when only one header was received, or an array when multiple were sent.
                    if ($cookieJar instanceof CookieJarInterface) {
                        $originHost = (new Uri($url))->getHost();
                        $setCookieHeaders = $headers['set-cookie'] ?? [];
                        if (\is_string($setCookieHeaders)) {
                            $setCookieHeaders = [$setCookieHeaders];
                        }
                        foreach ($setCookieHeaders as $setCookie) {
                            $cookie = Cookie::fromSetCookieHeader($setCookie, $originHost ?: null);
                            if ($cookie !== null) {
                                $cookieJar->setCookie($cookie);
                            }
                        }
                    }

                    $fileSize = file_exists($destination) ? filesize($destination) : 0;

                    $promise->resolve([
                        'file' => $destination,
                        'status' => $httpCode ?? 0,
                        'headers' => $headers,
                        'protocol_version' => $httpVersion,
                        'size' => $fileSize,
                    ]);
                }
            }
        );

        $promise->onCancel(function () use ($requestId, $file, $destination): void {
            Loop::cancelCurlRequest($requestId);
            if (\is_resource($file)) {
                fclose($file);
            }
            if (file_exists($destination)) {
                unlink($destination);
            }
        });

        return $promise;
    }

    /**
     * @inheritDoc
     */
    public function uploadFile(
        string $url,
        string $source,
        array $options = [],
        ?callable $onProgress = null,
    ): PromiseInterface {
        /** @var Promise<array{url: string, status: int, headers: array<mixed>, protocol_version: string|null}> $promise */
        $promise = new Promise();

        // Extract the cookie jar before array_filter strips string keys — without this
        // the jar reference is silently discarded and response cookies are never stored.
        $cookieJar = $options['_cookie_jar'] ?? null;
        unset($options['_cookie_jar']);

        if (! file_exists($source)) {
            $exception = new HttpStreamException("Cannot open file for reading: {$source}", 0, null, $url);
            $exception->setStreamState('file_open_failed');
            $promise->reject($exception);

            return $promise;
        }

        $fileSize = filesize($source);
        if ($fileSize === false) {
            $exception = new HttpStreamException("Cannot determine file size: {$source}", 0, null, $url);
            $exception->setStreamState('file_size_failed');
            $promise->reject($exception);

            return $promise;
        }

        $file = fopen($source, 'rb');
        if ($file === false) {
            $exception = new HttpStreamException("Cannot open file for reading: {$source}", 0, null, $url);
            $exception->setStreamState('file_open_failed');
            $promise->reject($exception);

            return $promise;
        }

        $curlOnlyOptions = array_filter($options, 'is_int', ARRAY_FILTER_USE_KEY);

        $uploadOptions = array_replace($curlOnlyOptions, [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILESIZE => $fileSize,
            CURLOPT_READFUNCTION => function ($ch, $fd, int $length) use ($file): string {
                return (string) fread($file, $length);
            },
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function (
                $ch,
                int $downloadTotal,
                int $downloaded,
                int $uploadTotal,
                int $uploaded,
            ) use ($promise, $onProgress): int {
                if ($promise->isCancelled()) {
                    return 1;
                }

                if ($onProgress !== null && $uploadTotal > 0) {
                    $onProgress(new UploadProgress($uploadTotal, $uploaded));
                }

                return 0;
            },
        ]);

        $requestId = Loop::addCurlRequest(
            $url,
            $uploadOptions,
            function (?string $error, $response, ?int $httpCode, array $headers = [], ?string $httpVersion = null) use ($url, $promise, $file, $cookieJar): void {
                fclose($file);

                if ($promise->isCancelled()) {
                    return;
                }

                if ($error !== null) {
                    $promise->reject(new NetworkException(
                        "Upload failed: {$error}",
                        0,
                        null,
                        $url,
                        $error
                    ));
                } else {
                    // CurlRequest normalizes captured header names to lowercase, so
                    // Set-Cookie arrives as 'set-cookie'. The value may be a plain string
                    // when only one header was received, or an array when multiple were sent.
                    if ($cookieJar instanceof CookieJarInterface) {
                        $originHost = (new Uri($url))->getHost();
                        $setCookieHeaders = $headers['set-cookie'] ?? [];
                        if (\is_string($setCookieHeaders)) {
                            $setCookieHeaders = [$setCookieHeaders];
                        }
                        foreach ($setCookieHeaders as $setCookie) {
                            $cookie = Cookie::fromSetCookieHeader($setCookie, $originHost ?: null);
                            if ($cookie !== null) {
                                $cookieJar->setCookie($cookie);
                            }
                        }
                    }

                    $promise->resolve([
                        'url' => $url,
                        'status' => $httpCode ?? 0,
                        'headers' => $headers,
                        'protocol_version' => $httpVersion,
                    ]);
                }
            }
        );

        $promise->onCancel(function () use ($requestId, $file): void {
            Loop::cancelCurlRequest($requestId);
            if (\is_resource($file)) {
                fclose($file);
            }
        });

        return $promise;
    }
}
