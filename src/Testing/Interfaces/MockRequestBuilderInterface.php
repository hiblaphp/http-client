<?php

namespace Hibla\Http\Testing\Interfaces;

/**
 * Complete contract for MockRequestBuilder.
 *
 * This interface combines all builder capabilities and ensures
 * that the MockRequestBuilder class implements all required methods.
 */
interface MockRequestBuilderInterface extends
    BuildsBasicMocksInterface,
    BuildsRequestExpectationsInterface,
    BuildsResponseHeadersInterface,
    BuildsFailureMocksInterface,
    BuildsRetrySequencesInterface,
    BuildsAdvancedScenariosInterface,
    BuildsSSEMocksInterface,
    BuildsSSERetrySequencesInterface,
    BuildsFileMocksInterface,
    BuildsCookieMocksInterface
{
    /**
     * Register this mock with the testing handler.
     */
    public function register(): void;
}
