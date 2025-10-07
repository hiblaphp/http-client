<?php

namespace Hibla\HttpClient\Testing;

use Hibla\HttpClient\Testing\Interfaces\MockRequestBuilderInterface;
use Hibla\HttpClient\Testing\Traits\RequestBuilder\BuildsAdvancedScenarios;
use Hibla\HttpClient\Testing\Traits\RequestBuilder\BuildsBasicMocks;
use Hibla\HttpClient\Testing\Traits\RequestBuilder\BuildsCookieMocks;
use Hibla\HttpClient\Testing\Traits\RequestBuilder\BuildsFailureMocks;
use Hibla\HttpClient\Testing\Traits\RequestBuilder\BuildsFileMocks;
use Hibla\HttpClient\Testing\Traits\RequestBuilder\BuildsRealisticSSEMocks;
use Hibla\HttpClient\Testing\Traits\RequestBuilder\BuildsRequestExpectations;
use Hibla\HttpClient\Testing\Traits\RequestBuilder\BuildsResponseHeaders;
use Hibla\HttpClient\Testing\Traits\RequestBuilder\BuildsRetrySequences;
use Hibla\HttpClient\Testing\Traits\RequestBuilder\BuildsSSEMocks;
use Hibla\HttpClient\Testing\Traits\RequestBuilder\BuildsSSERetrySequences;

/**
 * Builder for creating mocked HTTP requests with fluent API.
 *
 * This class implements all mock building capabilities through traits
 * and ensures compile-time verification via the MockRequestBuilderInterface.
 */
class MockRequestBuilder implements MockRequestBuilderInterface
{
    use BuildsBasicMocks;
    use BuildsRequestExpectations;
    use BuildsResponseHeaders;
    use BuildsFailureMocks;
    use BuildsRetrySequences;
    use BuildsAdvancedScenarios;
    use BuildsSSEMocks;
    use BuildsSSERetrySequences;
    use BuildsFileMocks;
    use BuildsCookieMocks;
    use BuildsRealisticSSEMocks;

    private TestingHttpHandler $handler;
    private MockedRequest $request;

    public function __construct(TestingHttpHandler $handler, string $method = '*')
    {
        $this->handler = $handler;
        $this->request = new MockedRequest($method);
    }

    /**
     * Get the current request configuration.
     *
     * @return MockedRequest The current request configuration.
     */
    protected function getRequest(): MockedRequest
    {
        return $this->request;
    }

    /**
     * Get the current testing handler instance.
     *
     * @return TestingHttpHandler The current testing handler instance.
     */
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
