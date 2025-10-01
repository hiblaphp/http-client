<?php

namespace Hibla\Http\Testing\Traits\RequestBuilder;

trait BuildsBasicMocks
{
    abstract protected function getRequest();

    /**
     * Set the URL pattern to match.
     */
    public function url(string $pattern): self
    {
        $this->getRequest()->setUrlPattern($pattern);
        return $this;
    }

    /**
     * Set the HTTP status code for the response.
     */
    public function respondWithStatus(int $status = 200): self
    {
        $this->getRequest()->setStatusCode($status);
        return $this;
    }

    /**
     * Shorthand for respondWithStatus().
     */
    public function status(int $status): self
    {
        return $this->respondWithStatus($status);
    }

    /**
     * Set the response body as a string.
     */
    public function respondWith(string $body): self
    {
        $this->getRequest()->setBody($body);
        return $this;
    }

    /**
     * Set the response body as JSON.
     */
    public function respondJson(array $data): self
    {
        $this->getRequest()->setBody(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $this->getRequest()->addResponseHeader('Content-Type', 'application/json');
        return $this;
    }

    /**
     * Add a delay before responding.
     */
    public function delay(float $seconds): self
    {
        $this->getRequest()->setDelay($seconds);
        return $this;
    }

    /**
     * Set a random delay range for realistic network simulation.
     */
    public function randomDelay(float $minSeconds, float $maxSeconds): self
    {
        if ($minSeconds > $maxSeconds) {
            throw new \InvalidArgumentException('Minimum delay cannot be greater than maximum delay');
        }

        $randomDelay = $this->generateAggressiveRandomFloat($minSeconds, $maxSeconds);
        $this->getRequest()->setDelay($randomDelay);
        return $this;
    }

    /**
     * Create a persistent mock with random delays for each request.
     */
    public function randomPersistentDelay(float $minSeconds, float $maxSeconds): self
    {
        if ($minSeconds > $maxSeconds) {
            throw new \InvalidArgumentException('Minimum delay cannot be greater than maximum delay');
        }

        $this->getRequest()->setRandomDelayRange($minSeconds, $maxSeconds);
        $this->persistent();
        return $this;
    }

    /**
     * Simulate a slow response.
     */
    public function slowResponse(float $delaySeconds): self
    {
        $this->getRequest()->setDelay($delaySeconds);
        return $this;
    }

    /**
     * Make this mock persistent (reusable for multiple requests).
     */
    public function persistent(): self
    {
        $this->getRequest()->setPersistent(true);
        return $this;
    }

    abstract protected function generateAggressiveRandomFloat(float $min, float $max): float;
}