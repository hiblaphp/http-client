<?php

namespace Hibla\Http\Testing\Utilities\Handlers;

use Hibla\Http\Testing\MockedRequest;

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