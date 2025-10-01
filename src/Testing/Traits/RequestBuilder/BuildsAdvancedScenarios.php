<?php

namespace Hibla\Http\Testing\Traits\RequestBuilder;

use Hibla\Http\Testing\MockedRequest;

trait BuildsAdvancedScenarios
{
    abstract protected function getRequest();
    abstract protected function getHandler();
    abstract public function respondWithStatus(int $status): self;
    abstract public function respondJson(array $data): self;

    /**
     * Create gradually improving response times (simulate network recovery).
     */
    public function slowlyImproveUntilAttempt(int $successAttempt, float $maxDelay = 10.0): self
    {
        for ($i = 1; $i < $successAttempt; $i++) {
            $delay = $maxDelay * (($successAttempt - $i) / ($successAttempt - 1));

            if ($delay > 5.0) {
                $mock = new MockedRequest($this->getRequest()->method ?? '*');
                $mock->setUrlPattern($this->getRequest()->urlPattern);
                $mock->setTimeout($delay);
                $mock->setRetryable(true);
            } else {
                $mock = new MockedRequest($this->getRequest()->method ?? '*');
                $mock->setUrlPattern($this->getRequest()->urlPattern);
                $mock->setStatusCode(200);
                $mock->setBody(json_encode(['attempt' => $i, 'delay' => $delay, 'status' => 'slow']));
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
    public function rateLimitedUntilAttempt(int $successAttempt): self
    {
        for ($i = 1; $i < $successAttempt; $i++) {
            $mock = new MockedRequest($this->getRequest()->method ?? '*');
            $mock->setUrlPattern($this->getRequest()->urlPattern);
            $mock->setStatusCode(429);
            $mock->setBody(json_encode([
                'error' => 'Too Many Requests',
                'retry_after' => pow(2, $i),
                'attempt' => $i,
            ]));
            $mock->addResponseHeader('Content-Type', 'application/json');
            $mock->addResponseHeader('Retry-After', (string) pow(2, $i));
            $mock->setRetryable(true);

            $this->getHandler()->addMockedRequest($mock);
        }

        $this->respondWithStatus(200);
        if (empty($this->getRequest()->getBody())) {
            $this->respondJson(['success' => true, 'attempt' => $successAttempt, 'message' => 'Rate limit cleared']);
        }

        return $this;
    }
}