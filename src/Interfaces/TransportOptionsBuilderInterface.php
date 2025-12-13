<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces;

use Hibla\HttpClient\ClientOptions;

/**
 * Contract for converting generic ClientOptions into transport-specific configuration.
 *
 * @template T
 */
interface TransportOptionsBuilderInterface
{
    /**
     * Build the transport-specific options.
     *
     * @param ClientOptions $options
     * @return T
     */
    public function build(ClientOptions $options): mixed;
}