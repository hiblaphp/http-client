<?php

namespace Hibla\Http\Testing\Interfaces;

interface AssertsSSEInterface
{
    public function assertSSEConnectionMade(string $url): void;
    public function assertNoSSEConnections(): void;
    public function assertSSELastEventId(string $expectedId, ?int $requestIndex = null): void;
}