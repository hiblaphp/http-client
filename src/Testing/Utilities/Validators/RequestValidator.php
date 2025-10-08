<?php

namespace Hibla\HttpClient\Testing\Utilities\Validators;

class RequestValidator
{
    /**
     * @param array<int, mixed> $curlOptions
     * @throws \InvalidArgumentException
     */
    public function validateNotSSERequest(array $curlOptions): void
    {
        if ($this->isSSERequest($curlOptions)) {
            throw new \InvalidArgumentException(
                'SSE requests should use $http->request()->sse() or $http->sse() directly, ' .
                'not send() or get()/post() methods'
            );
        }
    }

    /**
     * @param array<int, mixed> $curlOptions
     */
    private function isSSERequest(array $curlOptions): bool
    {
        if (! isset($curlOptions[CURLOPT_HTTPHEADER])) {
            return false;
        }

        $headers = $curlOptions[CURLOPT_HTTPHEADER];
        if (! is_array($headers)) {
            return false;
        }

        foreach ($headers as $header) {
            if (is_string($header) && stripos($header, 'Accept: text/event-stream') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function isSSERequested(array $options): bool
    {
        return isset($options['sse']) && $options['sse'] === true;
    }
}
