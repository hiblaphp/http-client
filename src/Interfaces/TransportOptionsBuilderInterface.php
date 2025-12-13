<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces;

use Hibla\HttpClient\ClientOptions;

/**
 * Interface for building transport-specific options from ClientOptions.
 *
 * @template-covariant TOptions
 */
interface TransportOptionsBuilderInterface
{
    /**
     * Build transport options for a standard request.
     *
     * @param ClientOptions $options
     * @return TOptions
     */
    public function build(ClientOptions $options);

    /**
     * Build transport options for streaming requests.
     *
     * @param ClientOptions $options
     * @return TOptions
     */
    public function buildForStreaming(ClientOptions $options);

    /**
     * Build transport options for download requests.
     *
     * @param ClientOptions $options
     * @param string $destination
     * @return TOptions
     */
    public function buildForDownload(ClientOptions $options, string $destination);

    /**
     * Build transport options for SSE (Server-Sent Events) requests.
     *
     * @param ClientOptions $options
     * @return TOptions
     */
    public function buildForSSE(ClientOptions $options);
}
