<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Traits\RequestBuilder;

trait BuildsResponseHeaders
{
    abstract protected function getRequest();

    /**
     * Add a response header.
     */
    public function respondWithHeader(string $name, string $value): static
    {
        $this->getRequest()->addResponseHeader($name, $value);

        return $this;
    }

    /**
     * Add multiple response headers.
     */
    public function respondWithHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->respondWithHeader($name, $value);
        }

        return $this;
    }

    /**
     * Set a sequence of body chunks to simulate realistic streaming.
     *
     * @param array<int, string> $chunks
     * @param float $delayPerChunk Seconds to wait between each chunk.
     * @param float $jitter Random variation (0.0 to 1.0) to apply to the delay.
     */
    public function respondWithChunks(array $chunks, float $delayPerChunk = 0, float $jitter = 0): static
    {
        $this->getRequest()->setBodySequence($chunks);
        $this->getRequest()->setChunkDelay($delayPerChunk);
        $this->getRequest()->setChunkJitter($jitter);

        return $this;
    }
}
