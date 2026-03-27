<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Handler;

use Hibla\HttpClient\Response;
use Hibla\HttpClient\SSE\SSEResponse;
use Hibla\HttpClient\StreamingResponse;
use Hibla\Promise\Interfaces\PromiseInterface;

/**
 * Contract for the unified fetch-style entry point on HttpHandler.
 *
 * fetch() is the single method through which all request modes are
 * accessible via a flat options array — useful for dynamic dispatch,
 * testing helpers, and interop with fetch-style calling conventions.
 *
 * For type-safe construction prefer the fluent PendingRequest API.
 * fetch() is primarily an internal dispatch surface and a convenience
 * for callers that build options programmatically.
 */
interface FetchHandlerInterface
{
    /**
     * Dispatch an HTTP request in whichever mode the options specify.
     *
     * Mode is determined by the presence of specific option keys:
     *
     *   - Default              → standard request, resolves to Response
     *   - 'stream' => true     → resolves to StreamingResponse
     *   - 'download'/'save_to' → resolves to download metadata array
     *   - 'sse' => true        → resolves to SSEResponse
     *
     * @param  string                    $url      The target URL.
     * @param  array<int|string, mixed>  $options  Option map. Recognised keys include:
     *                                             'method', 'headers', 'body',
     *                                             'stream', 'on_chunk'/'onChunk',
     *                                             'download'/'save_to',
     *                                             'sse', 'on_event'/'onEvent',
     *                                             'on_error'/'onError',
     *                                             'reconnect', 'retry',
     *                                             plus any raw transport options.
     * @return PromiseInterface<Response>|PromiseInterface<StreamingResponse>|PromiseInterface<SSEResponse>|PromiseInterface<array{file: string, status: int, headers: array<mixed>, protocol_version: string|null, size: int|false}>
     */
    public function fetch(string $url, array $options = []): PromiseInterface;

    /**
     * Normalise a fetch-style option map into transport-specific options.
     *
     * Separates concerns between option interpretation (this method)
     * and request execution (fetch()), making both independently testable.
     *
     * @param  string                    $url               The target URL.
     * @param  array<int|string, mixed>  $options           Raw fetch options.
     * @param  bool                      $ensureSSEHeaders  When true, ensures Accept and
     *                                                      Cache-Control headers required
     *                                                      for SSE are present.
     * @return array<int|string, mixed>
     */
    public function normalizeFetchOptions(string $url, array $options, bool $ensureSSEHeaders = false): array;
}