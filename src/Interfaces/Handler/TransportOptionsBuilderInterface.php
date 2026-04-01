<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Handler;

use Hibla\HttpClient\ValueObjects\ClientOptions;

/**
 * Contract for building transport-specific option arrays from a
 * normalised ClientOptions value object.
 *
 * Each build method targets a distinct request mode. Implementations
 * translate the agnostic ClientOptions into whatever the underlying
 * transport requires — cURL option arrays, stream context arrays,
 * Swoole coroutine config, etc.
 *
 * @template-covariant TOptions
 */
interface TransportOptionsBuilderInterface
{
    /**
     * Build options for a standard synchronous or async HTTP request.
     *
     * @param ClientOptions $options
     * @return TOptions
     */
    public function build(ClientOptions $options): mixed;

    /**
     * Build options for a streaming request where the response body
     * is consumed chunk by chunk rather than buffered in full.
     *
     * @param ClientOptions $options
     * @return TOptions
     */
    public function buildForStreaming(ClientOptions $options): mixed;

    /**
     * Build options for a file download request.
     *
     * The $destination path is included so the transport layer can
     * open the target file handle before the transfer begins.
     *
     * @param ClientOptions $options
     * @param string $destination Absolute path where the file should be written.
     * @return TOptions
     */
    public function buildForDownload(ClientOptions $options, string $destination): mixed;

    /**
     * Build options for a Server-Sent Events connection.
     *
     * SSE connections require specific headers (Accept: text/event-stream,
     * Cache-Control: no-cache) and typically disable timeouts so the
     * connection can remain open indefinitely.
     *
     * @param ClientOptions $options
     * @return TOptions
     */
    public function buildForSSE(ClientOptions $options): mixed;
}
