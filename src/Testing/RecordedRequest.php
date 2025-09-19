<?php

namespace Hibla\Http\Testing;

/**
 * Represents a recorded HTTP request for assertion purposes.
 */
class RecordedRequest
{
    public string $method;
    public string $url;
    public array $options;
    public float $timestamp;

    public function __construct(string $method, string $url, array $options, float $timestamp)
    {
        $this->method = $method;
        $this->url = $url;
        $this->options = $options;
        $this->timestamp = $timestamp;
    }
}
