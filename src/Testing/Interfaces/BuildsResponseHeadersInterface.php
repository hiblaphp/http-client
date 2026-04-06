<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Interfaces;

interface BuildsResponseHeadersInterface
{
    /**
     * Add a response header.
     * 
     * @param string|array<string> $value
     */
    public function respondWithHeader(string $name, string|array $value): static;

    /**
     * Add multiple response headers.
     *
     * @param array<string, string|array<string>> $headers
     */
    public function respondWithHeaders(array $headers): static;

    /**
     * Set a sequence of body chunks to simulate streaming.
     *
     * @param array<int, string> $chunks
     */
    public function respondWithChunks(array $chunks): static;
}
