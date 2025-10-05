<?php

namespace Hibla\Http\Interfaces;

/**
 * Complete HTTP client interface combining all functionality.
 *
 * This is the main interface that your Request class should implement,
 * providing the full feature set in a clean, organized way.
 */
interface CompleteHttpClientInterface extends
    AdvancedHttpClientInterface,
    HttpInterceptorInterface
{
}
