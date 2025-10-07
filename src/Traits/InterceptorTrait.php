<?php

namespace Hibla\HttpClient\Traits;

use Hibla\HttpClient\Handlers\RequestInterceptorHandler;
use Hibla\HttpClient\Handlers\ResponseInterceptorHandler;

trait InterceptorTrait
{
    private ?RequestInterceptorHandler $requestInterceptorHandler = null;
    private ?ResponseInterceptorHandler $responseInterceptorHandler = null;

    /**
     * Get or create the request interceptor handler.
     */
    private function getRequestInterceptorHandler(): RequestInterceptorHandler
    {
        if ($this->requestInterceptorHandler === null) {
            $this->requestInterceptorHandler = new RequestInterceptorHandler();
        }

        return $this->requestInterceptorHandler;
    }

    /**
     * Get or create the response interceptor handler.
     */
    private function getResponseInterceptorHandler(): ResponseInterceptorHandler
    {
        if ($this->responseInterceptorHandler === null) {
            $this->responseInterceptorHandler = new ResponseInterceptorHandler();
        }

        return $this->responseInterceptorHandler;
    }
}
