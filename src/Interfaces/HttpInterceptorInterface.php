<?php

namespace Hibla\Http\Interfaces;

/**
 * HTTP request/response interceptor interface.
 * 
 * Provides middleware-like functionality for intercepting and modifying
 * requests before they are sent and responses after they are received.
 */
interface HttpInterceptorInterface
{
    /**
     * Add a request interceptor.
     * 
     * The callback will receive the Request object before it is sent. It MUST
     * return a Request object, allowing for immutable modifications.
     */
    public function interceptRequest(callable $callback): self;

    /**
     * Add a response interceptor.
     * 
     * The callback will receive the final Response object. It MUST return a
     * Response object, allowing for inspection or modification.
     */
    public function interceptResponse(callable $callback): self;
}