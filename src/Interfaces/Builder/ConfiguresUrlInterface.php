<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Interfaces\Builder;

/**
 * Fluent interface for URI template parameter substitution.
 *
 * Supports two expansion styles:
 *   - Simple:   {param}  — value is percent-encoded.
 *   - Reserved: {+param} — special characters (/, ?, #, etc.) are preserved.
 *
 * Parameters that have no corresponding placeholder in the URL template
 * are silently ignored.
 */
interface ConfiguresUrlInterface
{
    /**
     * Set a single URI template parameter.
     *
     * @param  string  $key    Parameter name as it appears in the template (without braces).
     * @param  mixed   $value  Scalar value or stringable object. Non-stringable values are ignored.
     */
    public function withUrlParameter(string $key, mixed $value): static;

    /**
     * Set multiple URI template parameters at once.
     *
     * Merges with any previously set parameters — existing keys are overwritten,
     * keys not present in $parameters are preserved.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function withUrlParameters(array $parameters): static;
}