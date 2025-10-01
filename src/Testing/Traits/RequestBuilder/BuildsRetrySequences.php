<?php

namespace Hibla\Http\Testing\Traits\RequestBuilder;

use Hibla\Http\Testing\MockedRequest;

trait BuildsRetrySequences
{
    abstract protected function getRequest();
    abstract protected function getHandler();
    abstract public function respondWithStatus(int $status): self;
    abstract public function respondJson(array $data): self;

    /**
     * Create multiple mocks that fail until the specified attempt succeeds.
     */
    public function failUntilAttempt(int $successAttempt, string $failureError = 'Connection failed'): self
    {
        if ($successAttempt < 1) {
            throw new \InvalidArgumentException('Success attempt must be >= 1');
        }

        for ($i = 1; $i < $successAttempt; $i++) {
            $this->getHandler()->addMockedRequest(
                $this->createFailureMock($failureError . " (attempt {$i})", true)
            );
        }

        $this->respondWithStatus(200);
        if (empty($this->getRequest()->getBody())) {
            $this->respondJson(['success' => true, 'attempt' => $successAttempt]);
        }

        return $this;
    }

    /**
     * Create multiple mocks with different failure types until success.
     */
    public function failWithSequence(array $failures, string|array|null $successResponse = null): self
    {
        foreach ($failures as $index => $failure) {
            $attemptNumber = $index + 1;

            $mock = new MockedRequest($this->getRequest()->method);
            if ($this->getRequest()->urlPattern) {
                $mock->setUrlPattern($this->getRequest()->urlPattern);
            }

            if (is_string($failure)) {
                $mock->setError($failure . " (attempt {$attemptNumber})");
                $mock->setRetryable(true);
            } elseif (is_array($failure)) {
                $error = $failure['error'] ?? 'Request failed';
                $retryable = $failure['retryable'] ?? true;
                $delay = $failure['delay'] ?? 0.1;
                $statusCode = $failure['status'] ?? null;

                if ($statusCode !== null) {
                    $mock->setStatusCode($statusCode);
                    $mock->setBody(json_encode(['error' => $error]));
                    $mock->addResponseHeader('Content-Type', 'application/json');
                } else {
                    $mock->setError($error . " (attempt {$attemptNumber})");
                }
                $mock->setRetryable($retryable);
                $mock->setDelay($delay);
            }
            $this->getHandler()->addMockedRequest($mock);
        }

        $this->respondWithStatus(200);

        if ($successResponse !== null) {
            if (is_array($successResponse)) {
                $this->respondJson($successResponse);
            } else {
                $this->respondWith((string) $successResponse);
            }
        } else {
            $this->respondJson(['success' => true, 'attempt' => count($failures) + 1]);
        }

        return $this;
    }

    /**
     * Create timeout failures until success.
     */
    public function timeoutUntilAttempt(int $successAttempt, float $timeoutAfter = 5.0): self
    {
        for ($i = 1; $i < $successAttempt; $i++) {
            $mock = new MockedRequest($this->getRequest()->method ?? '*');
            $mock->setUrlPattern($this->getRequest()->urlPattern);
            $mock->setTimeout($timeoutAfter);
            $mock->setRetryable(true);
            $this->getHandler()->addMockedRequest($mock);
        }

        $this->respondWithStatus(200);
        if (empty($this->getRequest()->getBody())) {
            $this->respondJson(['success' => true, 'attempt' => $successAttempt, 'message' => 'Success after timeouts']);
        }

        return $this;
    }

    /**
     * Create HTTP status code failures until success.
     */
    public function statusFailuresUntilAttempt(int $successAttempt, int $failureStatus = 500): self
    {
        for ($i = 1; $i < $successAttempt; $i++) {
            $mock = new MockedRequest($this->getRequest()->method ?? '*');
            $mock->setUrlPattern($this->getRequest()->urlPattern);
            $mock->setStatusCode($failureStatus);
            $mock->setBody(json_encode(['error' => "Server error on attempt {$i}"]));
            $mock->addResponseHeader('Content-Type', 'application/json');

            if (in_array($failureStatus, [408, 429, 500, 502, 503, 504])) {
                $mock->setRetryable(true);
            }

            $this->getHandler()->addMockedRequest($mock);
        }

        $this->respondWithStatus(200);
        if (empty($this->getRequest()->getBody())) {
            $this->respondJson(['success' => true, 'attempt' => $successAttempt]);
        }

        return $this;
    }

    /**
     * Create a mixed sequence of different failure types.
     */
    public function mixedFailuresUntilAttempt(int $successAttempt): self
    {
        $failureTypes = ['timeout', 'connection', 'dns', 'ssl'];

        for ($i = 1; $i < $successAttempt; $i++) {
            $failureType = $failureTypes[($i - 1) % count($failureTypes)];

            $mock = new MockedRequest($this->getRequest()->method ?? '*');
            $mock->setUrlPattern($this->getRequest()->urlPattern);

            switch ($failureType) {
                case 'timeout':
                    $mock->setTimeout(2.0);
                    break;
                case 'connection':
                    $mock->setError("Connection failed (attempt {$i})");
                    $mock->setRetryable(true);
                    break;
                case 'dns':
                    $mock->setError("Could not resolve host (attempt {$i})");
                    $mock->setRetryable(true);
                    break;
                case 'ssl':
                    $mock->setError("SSL connection timeout (attempt {$i})");
                    $mock->setRetryable(true);
                    break;
            }

            $this->getHandler()->addMockedRequest($mock);
        }

        $this->respondWithStatus(200);
        if (empty($this->getRequest()->getBody())) {
            $this->respondJson([
                'success' => true,
                'attempt' => $successAttempt,
                'message' => 'Success after mixed failures',
            ]);
        }

        return $this;
    }

    /**
     * Create intermittent failures (some succeed, some fail).
     */
    public function intermittentFailures(array $pattern): self
    {
        foreach ($pattern as $index => $shouldFail) {
            $attemptNumber = $index + 1;
            $mock = new MockedRequest($this->getRequest()->method ?? '*');
            $mock->setUrlPattern($this->getRequest()->urlPattern);

            if ($shouldFail) {
                $mock->setError("Intermittent failure on attempt {$attemptNumber}");
                $mock->setRetryable(true);
            } else {
                $mock->setStatusCode(200);
                $mock->setBody(json_encode(['success' => true, 'attempt' => $attemptNumber]));
                $mock->addResponseHeader('Content-Type', 'application/json');
            }

            $this->getHandler()->addMockedRequest($mock);
        }

        return $this;
    }

    abstract protected function createFailureMock(string $error, bool $retryable): MockedRequest;
    abstract public function respondWith(string $body): self;
}