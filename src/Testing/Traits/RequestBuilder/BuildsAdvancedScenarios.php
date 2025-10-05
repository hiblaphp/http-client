<?php

namespace Hibla\Http\Testing\Traits\RequestBuilder;

use Hibla\Http\Testing\MockedRequest;

trait BuildsAdvancedScenarios
{
    abstract protected function getRequest();

    abstract protected function getHandler();

    abstract public function respondWithStatus(int $status): static;

    abstract public function respondJson(array $data): static;

    /**
     * Create gradually improving response times (simulate network recovery).
     */
    public function slowlyImproveUntilAttempt(int $successAttempt, float $maxDelay = 10.0): static
    {
        for ($i = 1; $i < $successAttempt; $i++) {
            $delay = $maxDelay * (($successAttempt - $i) / ($successAttempt - 1));

            if ($delay > 5.0) {
                $mock = new MockedRequest($this->getRequest()->method ?? '*');
                $urlPattern = $this->getRequest()->urlPattern;
                if ($urlPattern !== null) {
                    $mock->setUrlPattern($urlPattern);
                }
                $mock->setTimeout($delay);
                $mock->setRetryable(true);
            } else {
                $mock = new MockedRequest($this->getRequest()->method ?? '*');
                $urlPattern = $this->getRequest()->urlPattern;
                if ($urlPattern !== null) {
                    $mock->setUrlPattern($urlPattern);
                }
                $mock->setStatusCode(200);
                $body = json_encode(['attempt' => $i, 'delay' => $delay, 'status' => 'slow']);
                if ($body !== false) {
                    $mock->setBody($body);
                }
                $mock->setDelay($delay);
            }

            $this->getHandler()->addMockedRequest($mock);
        }

        $this->respondWithStatus(200);
        $this->respondJson(['success' => true, 'attempt' => $successAttempt, 'message' => 'Network recovered']);

        return $this;
    }

    /**
     * Simulate rate limiting with exponential backoff.
     */
    public function rateLimitedUntilAttempt(int $successAttempt): static
    {
        for ($i = 1; $i < $successAttempt; $i++) {
            $mock = new MockedRequest($this->getRequest()->method ?? '*');
            $urlPattern = $this->getRequest()->urlPattern;
            if ($urlPattern !== null) {
                $mock->setUrlPattern($urlPattern);
            }
            $mock->setStatusCode(429);
            $body = json_encode([
                'error' => 'Too Many Requests',
                'retry_after' => pow(2, $i),
                'attempt' => $i,
            ]);
            if ($body !== false) {
                $mock->setBody($body);
            }
            $mock->addResponseHeader('Content-Type', 'application/json');
            $mock->addResponseHeader('Retry-After', (string) pow(2, $i));
            $mock->setRetryable(true);

            $this->getHandler()->addMockedRequest($mock);
        }

        $this->respondWithStatus(200);
        if ($this->getRequest()->getBody() === '') {
            $this->respondJson(['success' => true, 'attempt' => $successAttempt, 'message' => 'Rate limit cleared']);
        }

        return $this;
    }
}
