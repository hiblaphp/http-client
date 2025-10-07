<?php

namespace Hibla\HttpClient\Testing\Traits\RequestBuilder;

use Hibla\HttpClient\Testing\MockedRequest;

trait BuildsRetrySequences
{
    abstract protected function getRequest();

    abstract protected function getHandler();

    abstract public function respondWithStatus(int $status): static;

    abstract public function respondJson(array $data): static;

    /**
     * Create multiple mocks that fail until the specified attempt succeeds.
     */
    public function failUntilAttempt(int $successAttempt, string $failureError = 'Connection failed'): static
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
        if ($this->getRequest()->getBody() === '') {
            $this->respondJson(['success' => true, 'attempt' => $successAttempt]);
        }

        return $this;
    }

    /**
     * Create multiple mocks with different failure types until success.
     *
     * @param array<int, string|array{error?: string, retryable?: bool, delay?: float, status?: int}> $failures
     * @param string|array<string, mixed>|null $successResponse
     */
    public function failWithSequence(array $failures, string|array|null $successResponse = null): static
    {
        foreach ($failures as $index => $failure) {
            $attemptNumber = $index + 1;

            $mock = new MockedRequest($this->getRequest()->method);
            $urlPattern = $this->getRequest()->urlPattern;
            if ($urlPattern !== null && $urlPattern !== '') {
                $mock->setUrlPattern($urlPattern);
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
                    $body = json_encode(['error' => $error]);
                    if ($body !== false) {
                        $mock->setBody($body);
                    }
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
                $this->respondWith($successResponse);
            }
        } else {
            $this->respondJson(['success' => true, 'attempt' => count($failures) + 1]);
        }

        return $this;
    }

    /**
     * Create timeout failures until success.
     */
    public function timeoutUntilAttempt(int $successAttempt, float $timeoutAfter = 5.0): static
    {
        for ($i = 1; $i < $successAttempt; $i++) {
            $mock = new MockedRequest($this->getRequest()->method ?? '*');
            $urlPattern = $this->getRequest()->urlPattern;
            if ($urlPattern !== null) {
                $mock->setUrlPattern($urlPattern);
            }
            $mock->setTimeout($timeoutAfter);
            $mock->setRetryable(true);
            $this->getHandler()->addMockedRequest($mock);
        }

        $this->respondWithStatus(200);
        if ($this->getRequest()->getBody() === '') {
            $this->respondJson(['success' => true, 'attempt' => $successAttempt, 'message' => 'Success after timeouts']);
        }

        return $this;
    }

    /**
     * Create HTTP status code failures until success.
     */
    public function statusFailuresUntilAttempt(int $successAttempt, int $failureStatus = 500): static
    {
        for ($i = 1; $i < $successAttempt; $i++) {
            $mock = new MockedRequest($this->getRequest()->method ?? '*');
            $urlPattern = $this->getRequest()->urlPattern;
            if ($urlPattern !== null) {
                $mock->setUrlPattern($urlPattern);
            }
            $mock->setStatusCode($failureStatus);
            $body = json_encode(['error' => "Server error on attempt {$i}"]);
            if ($body !== false) {
                $mock->setBody($body);
            }
            $mock->addResponseHeader('Content-Type', 'application/json');

            if (in_array($failureStatus, [408, 429, 500, 502, 503, 504], true)) {
                $mock->setRetryable(true);
            }

            $this->getHandler()->addMockedRequest($mock);
        }

        $this->respondWithStatus(200);
        if ($this->getRequest()->getBody() === '') {
            $this->respondJson(['success' => true, 'attempt' => $successAttempt]);
        }

        return $this;
    }

    /**
     * Create a mixed sequence of different failure types.
     */
    public function mixedFailuresUntilAttempt(int $successAttempt): static
    {
        $failureTypes = ['timeout', 'connection', 'dns', 'ssl'];

        for ($i = 1; $i < $successAttempt; $i++) {
            $failureType = $failureTypes[($i - 1) % count($failureTypes)];

            $mock = new MockedRequest($this->getRequest()->method ?? '*');
            $urlPattern = $this->getRequest()->urlPattern;
            if ($urlPattern !== null) {
                $mock->setUrlPattern($urlPattern);
            }

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
        if ($this->getRequest()->getBody() === '') {
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
     *
     * @param array<int, bool> $pattern Array of booleans (true = fail, false = succeed)
     */
    public function intermittentFailures(array $pattern): static
    {
        foreach ($pattern as $index => $shouldFail) {
            $attemptNumber = $index + 1;
            $mock = new MockedRequest($this->getRequest()->method ?? '*');
            $urlPattern = $this->getRequest()->urlPattern;
            if ($urlPattern !== null) {
                $mock->setUrlPattern($urlPattern);
            }

            if ($shouldFail) {
                $mock->setError("Intermittent failure on attempt {$attemptNumber}");
                $mock->setRetryable(true);
            } else {
                $mock->setStatusCode(200);
                $body = json_encode(['success' => true, 'attempt' => $attemptNumber]);
                if ($body !== false) {
                    $mock->setBody($body);
                }
                $mock->addResponseHeader('Content-Type', 'application/json');
            }

            $this->getHandler()->addMockedRequest($mock);
        }

        return $this;
    }

    abstract protected function createFailureMock(string $error, bool $retryable): MockedRequest;

    abstract public function respondWith(string $body): static;
}
