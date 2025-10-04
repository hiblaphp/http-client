<?php

namespace Hibla\Http\Testing\Utilities;

class RequestRecorder
{
    /**
     * @var array<int, RecordedRequest>
     */
    private array $requestHistory = [];

    private bool $recordRequests = true;

    /**
     * @param array<int, mixed> $options
     */
    public function recordRequest(string $method, string $url, array $options): void
    {
        if (!$this->recordRequests) {
            return;
        }

        $this->requestHistory[] = new RecordedRequest($method, $url, $options);
    }

    /**
     * @return array<int, RecordedRequest>
     */
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
        $count = count($this->requestHistory);

        if ($count === 0) {
            return null;
        }

        return $this->requestHistory[$count - 1];
    }

    /**
     * Get the first recorded request.
     */
    public function getFirstRequest(): ?RecordedRequest
    {
        $firstRequest = $this->requestHistory[0] ?? null;

        return $firstRequest instanceof RecordedRequest ? $firstRequest : null;
    }

    /**
     * Get a specific request by index.
     */
    public function getRequest(int $index): ?RecordedRequest
    {
        $request = $this->requestHistory[$index] ?? null;

        return $request instanceof RecordedRequest ? $request : null;
    }
}
