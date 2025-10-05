<?php

// src/Testing/Traits/RequestBuilder/BuildsBasicMocks.php

namespace Hibla\Http\Testing\Traits\RequestBuilder;

trait BuildsBasicMocks
{
    abstract protected function getRequest();

    /**
     * Set the URL pattern to match.
     */
    public function url(string $pattern): static
    {
        $this->getRequest()->setUrlPattern($pattern);

        return $this;
    }

    /**
     * Set the HTTP status code for the response.
     */
    public function respondWithStatus(int $status = 200): static
    {
        $this->getRequest()->setStatusCode($status);

        return $this;
    }

    /**
     * Shorthand for respondWithStatus().
     */
    public function status(int $status): static
    {
        return $this->respondWithStatus($status);
    }

    /**
     * Set the response body as a string.
     */
    public function respondWith(string $body): static
    {
        $this->getRequest()->setBody($body);

        return $this;
    }

    /**
     * Set the response body as JSON.
     *
     * @param array<string, mixed> $data
     */
    public function respondJson(array $data): static
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($body !== false) {
            $this->getRequest()->setBody($body);
            $this->getRequest()->addResponseHeader('Content-Type', 'application/json');
        }

        return $this;
    }

    /**
     * Add a delay before responding.
     */
    public function delay(float $seconds): static
    {
        $this->getRequest()->setDelay($seconds);

        return $this;
    }

    /**
     * Set a random delay range for realistic network simulation.
     */
    public function randomDelay(float $minSeconds, float $maxSeconds): static
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
    public function randomPersistentDelay(float $minSeconds, float $maxSeconds): static
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
    public function slowResponse(float $delaySeconds): static
    {
        $this->getRequest()->setDelay($delaySeconds);

        return $this;
    }

    /**
     * Make this mock persistent (reusable for multiple requests).
     */
    public function persistent(): static
    {
        $this->getRequest()->setPersistent(true);

        return $this;
    }

    abstract protected function generateAggressiveRandomFloat(float $min, float $max): float;
}
