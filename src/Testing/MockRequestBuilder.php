<?php

namespace Hibla\Http\Testing;

use Hibla\Http\Testing\Traits\RequestBuilder\BuildsBasicMocks;
use Hibla\Http\Testing\Traits\RequestBuilder\BuildsRequestExpectations;
use Hibla\Http\Testing\Traits\RequestBuilder\BuildsResponseHeaders;
use Hibla\Http\Testing\Traits\RequestBuilder\BuildsFailureMocks;
use Hibla\Http\Testing\Traits\RequestBuilder\BuildsRetrySequences;
use Hibla\Http\Testing\Traits\RequestBuilder\BuildsAdvancedScenarios;
use Hibla\Http\Testing\Traits\RequestBuilder\BuildsSSEMocks;
use Hibla\Http\Testing\Traits\RequestBuilder\BuildsFileMocks;
use Hibla\Http\Testing\Traits\RequestBuilder\BuildsCookieMocks;

class MockRequestBuilder
{
    use BuildsBasicMocks;
    use BuildsRequestExpectations;
    use BuildsResponseHeaders;
    use BuildsFailureMocks;
    use BuildsRetrySequences;
    use BuildsAdvancedScenarios;
    use BuildsSSEMocks;
    use BuildsFileMocks;
    use BuildsCookieMocks;

    private TestingHttpHandler $handler;
    private MockedRequest $request;

    public function __construct(TestingHttpHandler $handler, string $method = '*')
    {
        $this->handler = $handler;
        $this->request = new MockedRequest($method);
    }

    protected function getRequest(): MockedRequest
    {
        return $this->request;
    }

    protected function getHandler(): TestingHttpHandler
    {
        return $this->handler;
    }

    /**
     * Register this mock with the testing handler.
     */
    public function register(): void
    {
        $this->handler->addMockedRequest($this->request);
    }

    /**
     * Generate aggressive random float with high precision.
     */
    protected function generateAggressiveRandomFloat(float $min, float $max): float
    {
        $precision = 1000000;
        $randomInt = random_int(
            (int) ($min * $precision),
            (int) ($max * $precision)
        );

        return $randomInt / $precision;
    }
}