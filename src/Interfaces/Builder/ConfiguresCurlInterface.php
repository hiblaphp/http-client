<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Builder;

/**
 * Fluent interface for setting raw cURL options directly.
 *
 * This interface is intentionally narrow — it exists as an escape hatch
 * for edge cases not covered by the rest of the fluent API, such as
 * custom SSL certificate paths, interface binding, or low-level
 * transfer settings.
 *
 * Prefer the typed fluent methods over raw cURL options wherever possible.
 * Options set here bypass all validation and may conflict with options
 * set by the transport layer internally.
 */
interface ConfiguresCurlInterface
{
    /**
     * Set a single raw cURL option.
     *
     * @param  int    $option  A CURLOPT_* constant.
     * @param  mixed  $value   The value for the option.
     *
     * @throws \RuntimeException  When the cURL extension is not loaded.
     */
    public function withCurlOption(int $option, mixed $value): static;

    /**
     * Set multiple raw cURL options at once.
     *
     * Non-integer keys are silently ignored. Integer keys are merged
     * with any previously set cURL options — existing keys are overwritten.
     *
     * @param  array<int, mixed>  $options
     *
     * @throws \RuntimeException  When the cURL extension is not loaded.
     */
    public function withCurlOptions(array $options): static;
}
