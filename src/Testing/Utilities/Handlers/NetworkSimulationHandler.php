<?php

namespace Hibla\Http\Testing\Utilities\Handlers;

use Hibla\Http\Testing\TestingHttpHandler;
use Hibla\Http\Testing\Utilities\NetworkSimulator;

class NetworkSimulationHandler
{
    private NetworkSimulator $networkSimulator;
    private ?TestingHttpHandler $handler;

    public function __construct(
        NetworkSimulator $networkSimulator,
        ?TestingHttpHandler $handler = null
    ) {
        $this->networkSimulator = $networkSimulator;
        $this->handler = $handler;
    }

    /**
     * @return array{should_fail: bool, delay: float, error_message?: string}
     */
    public function simulate(): array
    {
        $rawResult = $this->networkSimulator->simulate();
        
        $shouldFail = $rawResult['should_fail'] ?? false;
        $delay = $rawResult['delay'] ?? 0.0;
        
        /** @var array{should_fail: bool, delay: float, error_message?: string} $result */
        $result = [
            'should_fail' => $shouldFail,
            'delay' => $delay,
        ];
        
        if ($shouldFail && isset($rawResult['error_message']) && is_string($rawResult['error_message'])) {
            $result['error_message'] = $rawResult['error_message'];
        }
        
        return $result;
    }

    public function generateGlobalRandomDelay(): float
    {
        return $this->handler !== null ? $this->handler->generateGlobalRandomDelay() : 0.0;
    }
}