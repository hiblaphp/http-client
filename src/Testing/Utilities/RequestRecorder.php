<?php

namespace Hibla\Http\Testing\Utilities;

class RequestRecorder
{
    private array $requestHistory = [];
    private bool $recordRequests = true;

    public function recordRequest(string $method, string $url, array $options): void
    {
        if (!$this->recordRequests) {
            return;
        }

        $this->requestHistory[] = new RecordedRequest($method, $url, $options);
    }

    public function getRequestHistory(): array
    {
        return $this->requestHistory;
    }

    public function setRecordRequests(bool $enabled): void
    {
        $this->recordRequests = $enabled;
    }

    public function reset(): void
    {
        $this->requestHistory = [];
    }

    /**
     * Get the last recorded request.
     */
    public function getLastRequest(): ?RecordedRequest
    {
        if (empty($this->requestHistory)) {
            return null;
        }

        return end($this->requestHistory);
    }

    /**
     * Get the first recorded request.
     */
    public function getFirstRequest(): ?RecordedRequest
    {
        return $this->requestHistory[0] ?? null;
    }

    /**
     * Get a specific request by index.
     */
    public function getRequest(int $index): ?RecordedRequest
    {
        return $this->requestHistory[$index] ?? null;
    }
}