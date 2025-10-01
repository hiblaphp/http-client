<?php

namespace Hibla\Http\Testing\Traits\RequestBuilder;

trait BuildsResponseHeaders
{
    abstract protected function getRequest();

    /**
     * Add a response header.
     */
    public function respondWithHeader(string $name, string $value): self
    {
        $this->getRequest()->addResponseHeader($name, $value);
        return $this;
    }

    /**
     * Add multiple response headers.
     */
    public function respondWithHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->respondWithHeader($name, $value);
        }
        return $this;
    }

    /**
     * Set a sequence of body chunks to simulate streaming.
     */
    public function respondWithChunks(array $chunks): self
    {
        $this->getRequest()->setBodySequence($chunks);
        return $this;
    }
}