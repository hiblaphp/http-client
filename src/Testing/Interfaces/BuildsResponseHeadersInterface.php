<?php

namespace Hibla\Http\Testing\Interfaces;

interface BuildsResponseHeadersInterface
{
    /**
     * Add a response header.
     */
    public function respondWithHeader(string $name, string $value): static;

    /**
     * Add multiple response headers.
     *
     * @param array<string, string> $headers
     */
    public function respondWithHeaders(array $headers): static;

    /**
     * Set a sequence of body chunks to simulate streaming.
     *
     * @param array<int, string> $chunks
     */
    public function respondWithChunks(array $chunks): static;
}
