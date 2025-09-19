<?php

namespace Hibla\Http\Testing\Utilities;

use Hibla\Http\Testing\RecordedRequest;

class RequestRecorder
{
    /** @var array<RecordedRequest> */
    private array $requestHistory = [];
    private bool $recordRequests = true;

    public function setRecordRequests(bool $enabled): void
    {
        $this->recordRequests = $enabled;
    }

    public function recordRequest(string $method, string $url, array $options): void
    {
        if (! $this->recordRequests) {
            return;
        }
        $this->requestHistory[] = new RecordedRequest($method, $url, $options, microtime(true));
    }

    public function getRequestHistory(): array
    {
        return $this->requestHistory;
    }

    public function reset(): void
    {
        $this->requestHistory = [];
    }
}
