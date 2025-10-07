<?php

namespace Hibla\HttpClient\Testing\Traits\RequestBuilder;

use Hibla\HttpClient\Testing\MockedRequest;

trait BuildsSSERetrySequences
{
    abstract protected function getRequest(): MockedRequest;

    abstract protected function getHandler();

    abstract public function respondWithSSE(array $events): static;

    abstract public function addSSEEvent(?string $data = null, ?string $event = null, ?string $id = null, ?int $retry = null): static;

    /**
     * SSE connection that fails until the specified attempt succeeds.
     *
     * @param int $successAttempt The attempt number that should succeed (1-based)
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $successEvents Events to send on successful connection
     * @param string $failureError Error message for failed attempts
     */
    public function sseFailUntilAttempt(
        int $successAttempt,
        array $successEvents = [],
        string $failureError = 'SSE Connection failed'
    ): static {
        if ($successAttempt < 1) {
            throw new \InvalidArgumentException('Success attempt must be >= 1');
        }

        // Create failure mocks for all attempts before success
        for ($i = 1; $i < $successAttempt; $i++) {
            $mock = $this->createSSEFailureMock($failureError . " (attempt {$i})");
            $this->getHandler()->addMockedRequest($mock);
        }

        // Configure the final successful response
        $this->respondWithSSE($successEvents);
        if ($successEvents === []) {
            $data = json_encode(['success' => true, 'attempt' => $successAttempt]);
            if ($data !== false) {
                $this->addSSEEvent(
                    data: $data,
                    event: 'message'
                );
            }
        }

        return $this;
    }

    /**
     * SSE connection with a sequence of different failure types.
     *
     * @param array<int, string|array{error?: string, retryable?: bool, delay?: float}> $failures Array of failure specifications
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $successEvents Events to send on final successful connection
     */
    public function sseFailWithSequence(array $failures, array $successEvents = []): static
    {
        foreach ($failures as $index => $failure) {
            $attemptNumber = $index + 1;

            $mock = new MockedRequest($this->getRequest()->getMethod());
            $urlPattern = $this->getRequest()->getUrlPattern();
            if ($urlPattern !== null) {
                $mock->setUrlPattern($urlPattern);
            }
            $mock->asSSE();
            $mock->addResponseHeader('Content-Type', 'text/event-stream');
            $mock->addResponseHeader('Cache-Control', 'no-cache');
            $mock->addResponseHeader('Connection', 'keep-alive');

            if (is_string($failure)) {
                $mock->setError($failure . " (attempt {$attemptNumber})");
                $mock->setRetryable(true);
                $mock->setDelay(0.1);
            } elseif (is_array($failure)) {
                $error = $failure['error'] ?? 'SSE connection failed';
                $retryable = $failure['retryable'] ?? true;
                $delay = $failure['delay'] ?? 0.1;

                $mock->setError($error . " (attempt {$attemptNumber})");
                $mock->setRetryable($retryable);
                $mock->setDelay($delay);
            }

            $this->getHandler()->addMockedRequest($mock);
        }

        // Configure the final successful response
        $this->respondWithSSE($successEvents);
        if ($successEvents === []) {
            $data = json_encode([
                'success' => true,
                'attempt' => count($failures) + 1,
                'message' => 'SSE connection established',
            ]);
            if ($data !== false) {
                $this->addSSEEvent(
                    data: $data,
                    event: 'connected'
                );
            }
        }

        return $this;
    }

    /**
     * SSE connection that times out until success.
     *
     * @param int $successAttempt The attempt number that should succeed
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $successEvents Events to send on successful connection
     * @param float $timeoutAfter Timeout duration in seconds
     */
    public function sseTimeoutUntilAttempt(
        int $successAttempt,
        array $successEvents = [],
        float $timeoutAfter = 5.0
    ): static {
        for ($i = 1; $i < $successAttempt; $i++) {
            $mock = new MockedRequest($this->getRequest()->getMethod());
            $urlPattern = $this->getRequest()->getUrlPattern();
            if ($urlPattern !== null) {
                $mock->setUrlPattern($urlPattern);
            }
            $mock->asSSE();
            $mock->setTimeout($timeoutAfter);
            $mock->setRetryable(true);
            $mock->addResponseHeader('Content-Type', 'text/event-stream');
            $mock->addResponseHeader('Cache-Control', 'no-cache');
            $mock->addResponseHeader('Connection', 'keep-alive');

            $this->getHandler()->addMockedRequest($mock);
        }

        $this->respondWithSSE($successEvents);
        if ($successEvents === []) {
            $data = json_encode([
                'success' => true,
                'attempt' => $successAttempt,
                'message' => 'SSE connection established after timeouts',
            ]);
            if ($data !== false) {
                $this->addSSEEvent(
                    data: $data,
                    event: 'connected'
                );
            }
        }

        return $this;
    }

    /**
     * SSE connection with intermittent failures.
     *
     * @param array<int, bool> $pattern Array of booleans (true = fail, false = succeed)
     */
    public function sseIntermittentFailures(array $pattern): static
    {
        foreach ($pattern as $index => $shouldFail) {
            $attemptNumber = $index + 1;
            $mock = new MockedRequest($this->getRequest()->getMethod());
            $urlPattern = $this->getRequest()->getUrlPattern();
            if ($urlPattern !== null) {
                $mock->setUrlPattern($urlPattern);
            }
            $mock->asSSE();
            $mock->addResponseHeader('Content-Type', 'text/event-stream');
            $mock->addResponseHeader('Cache-Control', 'no-cache');
            $mock->addResponseHeader('Connection', 'keep-alive');

            if ($shouldFail) {
                $mock->setError("Intermittent SSE failure on attempt {$attemptNumber}");
                $mock->setRetryable(true);
                $mock->setDelay(0.1);
            } else {
                $data = json_encode([
                    'success' => true,
                    'attempt' => $attemptNumber,
                    'status' => 'connected',
                ]);
                if ($data !== false) {
                    $mock->addSSEEvent([
                        'data' => $data,
                        'event' => 'message',
                    ]);
                }
            }

            $this->getHandler()->addMockedRequest($mock);
        }

        return $this;
    }

    /**
     * SSE connection with network error types until success.
     *
     * @param int $successAttempt The attempt number that should succeed
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $successEvents Events to send on successful connection
     */
    public function sseNetworkErrorsUntilAttempt(
        int $successAttempt,
        array $successEvents = []
    ): static {
        $errorTypes = [
            'Connection refused',
            'Connection reset',
            'Connection timed out',
            'Network is unreachable',
            'Could not resolve host',
            'SSL connection timeout',
        ];

        for ($i = 1; $i < $successAttempt; $i++) {
            $errorType = $errorTypes[($i - 1) % count($errorTypes)];
            $mock = $this->createSSEFailureMock($errorType . " (attempt {$i})");
            $this->getHandler()->addMockedRequest($mock);
        }

        $this->respondWithSSE($successEvents);
        if ($successEvents === []) {
            $data = json_encode([
                'success' => true,
                'attempt' => $successAttempt,
                'message' => 'Network recovered, SSE connected',
            ]);
            if ($data !== false) {
                $this->addSSEEvent(
                    data: $data,
                    event: 'connected'
                );
            }
        }

        return $this;
    }

    /**
     * SSE connection that gradually improves (simulates network recovery).
     *
     * @param int $successAttempt The attempt number that should succeed
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $successEvents Events to send on successful connection
     * @param float $maxDelay Maximum delay for worst connection
     */
    public function sseSlowlyImproveUntilAttempt(
        int $successAttempt,
        array $successEvents = [],
        float $maxDelay = 10.0
    ): static {
        for ($i = 1; $i < $successAttempt; $i++) {
            // Calculate delay with exponential improvement
            $delay = $maxDelay * (($successAttempt - $i) / ($successAttempt - 1));

            $mock = new MockedRequest($this->getRequest()->getMethod());
            $urlPattern = $this->getRequest()->getUrlPattern();
            if ($urlPattern !== null) {
                $mock->setUrlPattern($urlPattern);
            }
            $mock->asSSE();
            $mock->addResponseHeader('Content-Type', 'text/event-stream');
            $mock->addResponseHeader('Cache-Control', 'no-cache');
            $mock->addResponseHeader('Connection', 'keep-alive');

            if ($delay > 5.0) {
                // Severe delay = timeout
                $mock->setTimeout($delay);
                $mock->setRetryable(true);
            } else {
                // Moderate delay = slow connection with some data
                $mock->setDelay($delay);
                $data = json_encode([
                    'attempt' => $i,
                    'delay' => round($delay, 2),
                    'status' => 'slow_connection',
                ]);
                if ($data !== false) {
                    $mock->addSSEEvent([
                        'data' => $data,
                        'event' => 'message',
                    ]);
                }
            }

            $this->getHandler()->addMockedRequest($mock);
        }

        $this->respondWithSSE($successEvents);
        if ($successEvents === []) {
            $data = json_encode([
                'success' => true,
                'attempt' => $successAttempt,
                'message' => 'Network fully recovered',
            ]);
            if ($data !== false) {
                $this->addSSEEvent(
                    data: $data,
                    event: 'connected'
                );
            }
        }

        return $this;
    }

    /**
     * SSE connection that drops after receiving some events (then needs reconnection).
     *
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $eventsBeforeDrop Events to send before dropping connection
     * @param string $dropError Error message when connection drops
     * @param bool $retryable Whether the error is retryable
     */
    public function sseDropAfterEvents(
        array $eventsBeforeDrop,
        string $dropError = 'Connection lost',
        bool $retryable = true
    ): static {
        $this->respondWithSSE($eventsBeforeDrop);
        $this->getRequest()->setError($dropError);
        $this->getRequest()->setRetryable($retryable);

        return $this;
    }

    /**
     * SSE reconnection scenario: mock resumption from a specific event ID.
     *
     * @param string $lastEventId The last event ID received by client
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $eventsAfterResume Events to send after resuming
     */
    public function sseReconnectFromEventId(
        string $lastEventId,
        array $eventsAfterResume
    ): static {
        $this->getRequest()->addHeaderMatcher('Last-Event-ID', $lastEventId);
        $this->respondWithSSE($eventsAfterResume);

        return $this;
    }

    /**
     * SSE connection with mixed failure types (timeout, network errors, etc.).
     *
     * @param int $successAttempt The attempt number that should succeed
     */
    public function sseMixedFailuresUntilAttempt(int $successAttempt): static
    {
        $failureTypes = [
            ['type' => 'timeout', 'delay' => 5.0],
            ['type' => 'error', 'message' => 'Connection refused'],
            ['type' => 'error', 'message' => 'Could not resolve host'],
            ['type' => 'error', 'message' => 'SSL connection timeout'],
        ];

        for ($i = 1; $i < $successAttempt; $i++) {
            $failureType = $failureTypes[($i - 1) % count($failureTypes)];

            $mock = new MockedRequest($this->getRequest()->getMethod());
            $urlPattern = $this->getRequest()->getUrlPattern();
            if ($urlPattern !== null) {
                $mock->setUrlPattern($urlPattern);
            }
            $mock->asSSE();
            $mock->addResponseHeader('Content-Type', 'text/event-stream');
            $mock->addResponseHeader('Cache-Control', 'no-cache');
            $mock->addResponseHeader('Connection', 'keep-alive');

            if ($failureType['type'] === 'timeout') {
                $mock->setTimeout($failureType['delay']);
                $mock->setRetryable(true);
            } else {
                $mock->setError($failureType['message'] . " (attempt {$i})");
                $mock->setRetryable(true);
                $mock->setDelay(0.1);
            }

            $this->getHandler()->addMockedRequest($mock);
        }

        $this->respondWithSSE([]);
        $data = json_encode([
            'success' => true,
            'attempt' => $successAttempt,
            'message' => 'Success after mixed failures',
        ]);
        if ($data !== false) {
            $this->addSSEEvent(
                data: $data,
                event: 'connected'
            );
        }

        return $this;
    }

    /**
     * SSE with rate limiting (429 status) until success.
     *
     * @param int $successAttempt The attempt number that should succeed
     * @param array<int, array{data?: string, event?: string, id?: string, retry?: int}> $successEvents Events to send on successful connection
     */
    public function sseRateLimitedUntilAttempt(
        int $successAttempt,
        array $successEvents = []
    ): static {
        for ($i = 1; $i < $successAttempt; $i++) {
            $retryAfter = pow(2, $i - 1); // Exponential backoff

            $mock = new MockedRequest($this->getRequest()->getMethod());
            $urlPattern = $this->getRequest()->getUrlPattern();
            if ($urlPattern !== null) {
                $mock->setUrlPattern($urlPattern);
            }
            $mock->asSSE();
            $mock->setStatusCode(429);
            $body = json_encode([
                'error' => 'Too Many Requests',
                'retry_after' => $retryAfter,
                'attempt' => $i,
            ]);
            if ($body !== false) {
                $mock->setBody($body);
            }
            $mock->addResponseHeader('Content-Type', 'application/json');
            $mock->addResponseHeader('Retry-After', (string) $retryAfter);
            $mock->setRetryable(true);
            $mock->setDelay(0.1);

            $this->getHandler()->addMockedRequest($mock);
        }

        $this->respondWithSSE($successEvents);
        if ($successEvents === []) {
            $data = json_encode([
                'success' => true,
                'attempt' => $successAttempt,
                'message' => 'Rate limit cleared',
            ]);
            if ($data !== false) {
                $this->addSSEEvent(
                    data: $data,
                    event: 'connected'
                );
            }
        }

        return $this;
    }

    /**
     * Helper method to create an SSE failure mock.
     *
     * @param string $error Error message
     * @return MockedRequest
     */
    protected function createSSEFailureMock(string $error): MockedRequest
    {
        $mock = new MockedRequest($this->getRequest()->getMethod());
        $urlPattern = $this->getRequest()->getUrlPattern();
        if ($urlPattern !== null) {
            $mock->setUrlPattern($urlPattern);
        }
        $mock->asSSE();
        $mock->setError($error);
        $mock->setRetryable(true);
        $mock->setDelay(0.1);
        $mock->addResponseHeader('Content-Type', 'text/event-stream');
        $mock->addResponseHeader('Cache-Control', 'no-cache');
        $mock->addResponseHeader('Connection', 'keep-alive');

        return $mock;
    }
}
