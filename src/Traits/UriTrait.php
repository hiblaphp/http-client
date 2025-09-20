<?php

namespace Hibla\Http\Traits;

trait UriTrait
{
    /**
     * Updates the Host header from the URI if necessary.
     */
    private function updateHostFromUri(): self
    {
        $host = $this->uri->getHost();
        if ($host === '') {
            return $this;
        }

        if (($port = $this->uri->getPort()) !== null) {
            $host .= ':' . $port;
        }

        return $this->withHeader('Host', $host);
    }
}