<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces;

use Hibla\HttpClient\Interfaces\Builder\ConfiguresCurlInterface;
use Hibla\HttpClient\Interfaces\Builder\ConfiguresFilesInterface;
use Hibla\HttpClient\Interfaces\Builder\ConfiguresProxyInterface;
use Hibla\HttpClient\Interfaces\Builder\ConfiguresRetryInterface;
use Hibla\HttpClient\Interfaces\Builder\ConfiguresTransportInterface;
use Hibla\HttpClient\Interfaces\Builder\ConfiguresUrlInterface;
use Hibla\HttpClient\Interfaces\Execution\ExecutesRequestsInterface;
use Hibla\HttpClient\Interfaces\Execution\ExecutesStreamingInterface;
use Hibla\HttpClient\Interfaces\Execution\HttpInterceptorInterface;

/**
 * Marker interface declared by Request.
 *
 * Exists as a named type distinct from EnhancedRequestInterface so
 * that internal code — handlers, factories, the Http facade — can
 * accept or return Request specifically without depending on
 * the concrete class directly.
 *
 * Userland code should type-hint against EnhancedRequestInterface
 * rather than this interface. The distinction matters because:
 *
 *   - EnhancedRequestInterface is the stable public API contract.
 *     It will not gain new methods without a major version bump.
 *
 *   - CompleteHttpClientInterface may grow internal-only methods
 *     over time (e.g. setHandler(), setTransportOptionsBuilder())
 *     that are not part of the public API surface and should not
 *     be visible to userland callers.
 */
interface HttpClientInterface extends
    RequestInterface,
    ConfiguresFilesInterface,
    ConfiguresUrlInterface,
    ConfiguresTransportInterface,
    ConfiguresProxyInterface,
    ConfiguresRetryInterface,
    ConfiguresCurlInterface,
    HttpInterceptorInterface,
    ExecutesRequestsInterface,
    ExecutesStreamingInterface
{
}
