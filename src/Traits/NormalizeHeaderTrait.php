<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Traits;

trait NormalizeHeaderTrait
{
    /**
     * Normalizes headers array to the expected format.
     *
     * @param array<mixed> $headers The headers to normalize.
     *
     * @return array<string, array<string>|string> Normalized headers.
     */
    private function normalizeHeaders(array $headers): array
    {
        /** @var array<string, array<string>|string> $normalized */
        $normalized = [];

        foreach ($headers as $key => $value) {
            if (\is_string($key)) {
                $key = strtolower($key);
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
     * Header names are lowercased so that HTTP/2 (which mandates lowercase on
     * the wire) and HTTP/1.1 (case-insensitive by RFC 7230) are treated
     * identically by all consumers. Lookup must always use lowercase keys.
     *
     * Groups multiple headers with the same name (e.g., set-cookie) into
     * a single array under that name, complying with PSR-7 structure.
     *
     * @param string[] $rawHeaders Array of raw header lines (e.g., "Content-Type: text/html").
     *
     * @return array<string, array<int, string>> Parsed headers.
     */
    private function parseRawHeaders(array $rawHeaders): array
    {
        $parsed = [];

        foreach ($rawHeaders as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $name = strtolower(trim($name));
                $value = trim($value);

                $parsed[$name][] = $value;
            }
        }

        return $parsed;
    }
}
