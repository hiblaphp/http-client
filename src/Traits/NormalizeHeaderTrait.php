<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Traits;

trait NormalizeHeaderTrait
{
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
            if (\is_string($key)) {
                if (\is_string($value)) {
                    $normalized[$key] = $value;
                } elseif (\is_array($value)) {
                    $stringValues = \array_filter($value, 'is_string');
                    if (\count($stringValues) > 0) {
                        $normalized[$key] = \array_values($stringValues);
                    }
                }
            }
        }

        return $normalized;
    }

    /**
     * Parses raw header strings from cURL into an associative array of arrays.
     *
     * Groups multiple headers with the same name (e.g., Set-Cookie) into
     * a single array under that name, complying with PSR-7 structure.
     *
     * @param string[] $rawHeaders Array of raw header lines (e.g., "Content-Type: text/html").
     * @return array<string, array<int, string>> Parsed headers.
     */
    private function parseRawHeaders(array $rawHeaders): array
    {
        $parsed = [];

        foreach ($rawHeaders as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $name = trim($name);
                $value = trim($value);

                $parsed[$name][] = $value;
            }
        }

        return $parsed;
    }
}
