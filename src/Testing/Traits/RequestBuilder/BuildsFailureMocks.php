<?php

namespace Hibla\Http\Testing\Traits\RequestBuilder;

use Hibla\Http\Testing\MockedRequest;

trait BuildsFailureMocks
{
    abstract protected function getRequest();

    /**
     * Make the mock fail with an error.
     */
    public function fail(string $error = 'Mocked request failure'): static
    {
        $this->getRequest()->setError($error);
        return $this;
    }

    /**
     * Simulate a timeout failure.
     */
    public function timeout(float $seconds = 30.0): static
    {
        $this->getRequest()->setTimeout($seconds);
        return $this;
    }

    /**
     * Simulate a timeout failure that can be retried.
     */
    public function timeoutFailure(float $timeoutAfter = 30.0, ?string $customMessage = null): static
    {
        if ($customMessage !== null && $customMessage !== '') {
            $this->getRequest()->setError($customMessage);
        } else {
            $this->getRequest()->setTimeout($timeoutAfter);
        }
        $this->getRequest()->setRetryable(true);
        return $this;
    }

    /**
     * Simulate a retryable failure.
     */
    public function retryableFailure(string $error = 'Connection failed'): static
    {
        $this->getRequest()->setError($error);
        $this->getRequest()->setRetryable(true);
        return $this;
    }

    /**
     * Simulate a network error.
     */
    public function networkError(string $errorType = 'connection'): static
    {
        $errors = [
            'connection' => 'Connection failed',
            'timeout' => 'Connection timed out',
            'resolve' => 'Could not resolve host',
            'ssl' => 'SSL connection timeout',
        ];

        $error = $errors[$errorType] ?? $errorType;
        $this->getRequest()->setError($error);
        $this->getRequest()->setRetryable(true);
        return $this;
    }

    /**
     * Create a failure mock for retry scenarios.
     */
    protected function createFailureMock(string $error, bool $retryable): MockedRequest
    {
        $mock = new MockedRequest($this->getRequest()->method ?? '*');
        $urlPattern = $this->getRequest()->urlPattern;
        if ($urlPattern !== null && $urlPattern !== '') {
            $mock->setUrlPattern($urlPattern);
        }
        $mock->setError($error);
        $mock->setRetryable($retryable);
        $mock->setDelay(0.1);

        return $mock;
    }
}