<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Traits;

use Hibla\HttpClient\Exceptions\HttpStreamException;
use Hibla\HttpClient\Stream;

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
     * Creates a new stream from a string.
     *
     * @param  string  $content  The initial content of the stream.
     * @return Stream A new Stream object.
     *
     * @throws HttpStreamException If temporary stream creation fails.
     *
     * @internal This method is designed for extension by TestingHttpHandler for stream mocking.
     */
    public function createStream(string $content = ''): Stream
    {
        $resource = fopen('php://temp', 'w+b');
        if ($resource === false) {
            throw new HttpStreamException('Failed to create temporary stream');
        }

        if ($content !== '') {
            fwrite($resource, $content);
            rewind($resource);
        }

        return new Stream($resource);
    }

    /**
     * Safely converts mixed values to string.
     *
     * @param  mixed  $value  The value to convert to string
     */
    private function convertToString($value): string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_scalar($value) || \is_null($value)) {
            return (string) $value;
        }
        if (\is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return var_export($value, true);
    }
}
