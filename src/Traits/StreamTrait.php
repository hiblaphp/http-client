<?php

namespace Hibla\Http\Traits;

use Hibla\Http\Exceptions\HttpStreamException;
use Hibla\Http\Stream;

trait StreamTrait
{
    /**
     * Creates a temporary stream resource safely.
     */
    private function createTempStream(): Stream
    {
        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            throw new HttpStreamException('Unable to create temporary stream');
        }

        return new Stream($resource, null);
    }

    /**
     * Safely converts mixed values to string.
     *
     * @param  mixed  $value  The value to convert to string
     */
    private function convertToString($value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value) || is_null($value)) {
            return (string) $value;
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return var_export($value, true);
    }
}
