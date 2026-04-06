<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Traits\RequestBuilder;

trait BuildsResponseHeaders
{
    abstract protected function getRequest();

    /**
     * Add a response header.
     * 
     * @param string|array<string> $value
     */
    public function respondWithHeader(string $name, string|array $value): static
    {
        if (\is_array($value)) {
            foreach ($value as $v) {
                $this->getRequest()->addResponseHeader($name, (string) $v);
            }
        } else {
            $this->getRequest()->addResponseHeader($name, $value);
        }

        return $this;
    }

    /**
     * Add multiple response headers.
     *
     * @param array<string, string|array<string>> $headers
     */
    public function respondWithHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->respondWithHeader($name, $value);
        }

        return $this;
    }

    /**
     * Set a sequence of body chunks to simulate streaming.
     */
    public function respondWithChunks(array $chunks): static
    {
        $this->getRequest()->setBodySequence($chunks);

        return $this;
    }
}
