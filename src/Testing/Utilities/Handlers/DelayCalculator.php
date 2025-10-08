<?php

namespace Hibla\HttpClient\Testing\Utilities\Handlers;

use Hibla\HttpClient\Testing\MockedRequest;

class DelayCalculator
{
    /**
     * @param array{delay?: float} $networkConditions
     */
    public function calculateTotalDelay(
        MockedRequest $mock,
        array $networkConditions,
        float $globalDelay
    ): float {
        $mockDelay = $mock->getDelay();

        return max(
            $mockDelay,
            $globalDelay,
            $networkConditions['delay'] ?? 0
        );
    }
}
