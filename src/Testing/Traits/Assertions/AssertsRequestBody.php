<?php

namespace Hibla\Http\Testing\Traits\Assertions;

use Hibla\Http\Testing\Exceptions\MockAssertionException;

trait AssertsRequestBody
{
    /**
     * @return array<int, \Hibla\Http\Testing\Utilities\RecordedRequest>
     */
    abstract public function getRequestHistory(): array;

    /**
     * Assert that a request was made with specific body content.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param string $expectedBody Expected body content
     * @throws MockAssertionException
     */
    public function assertRequestWithBody(string $method, string $url, string $expectedBody): void
    {
        foreach ($this->getRequestHistory() as $request) {
            if (
                strtoupper($request->getMethod()) === strtoupper($method) &&
                $request->getUrl() === $url &&
                $request->getBody() === $expectedBody
            ) {
                return;
            }
        }

        throw new MockAssertionException(
            "Expected request with body not found: {$method} {$url}"
        );
    }

    /**
     * Assert that a request was made with body containing a string.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param string $needle String to search for
     * @throws MockAssertionException
     */
    public function assertRequestBodyContains(string $method, string $url, string $needle): void
    {
        foreach ($this->getRequestHistory() as $request) {
            if (
                strtoupper($request->getMethod()) === strtoupper($method) &&
                $request->getUrl() === $url
            ) {
                $body = $request->getBody();

                if ($body !== null && str_contains($body, $needle)) {
                    return;
                }
            }
        }

        throw new MockAssertionException(
            "Expected request body containing '{$needle}' not found: {$method} {$url}"
        );
    }

    /**
     * Assert that a request was made with JSON body.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param array<mixed> $expectedJson Expected JSON data
     * @throws MockAssertionException
     */
    public function assertRequestWithJson(string $method, string $url, array $expectedJson): void
    {
        foreach ($this->getRequestHistory() as $request) {
            if (
                strtoupper($request->getMethod()) === strtoupper($method) &&
                $request->getUrl() === $url
            ) {
                $json = $request->getJson();

                if ($json === $expectedJson) {
                    return;
                }
            }
        }

        throw new MockAssertionException(
            "Expected request with JSON not found: {$method} {$url}"
        );
    }

    /**
     * Assert that a request was made with JSON containing specific keys.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param array<mixed> $expectedKeys Expected keys and values
     * @throws MockAssertionException
     */
    public function assertRequestJsonContains(string $method, string $url, array $expectedKeys): void
    {
        foreach ($this->getRequestHistory() as $request) {
            if (
                strtoupper($request->getMethod()) === strtoupper($method) &&
                $request->getUrl() === $url
            ) {
                $json = $request->getJson();

                if ($json !== null && $this->arrayContains($json, $expectedKeys)) {
                    return;
                }
            }
        }

        throw new MockAssertionException(
            "Expected request with JSON keys not found: {$method} {$url}"
        );
    }

    /**
     * Assert that a request was made with a JSON path value.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param string $path Dot-notation path (e.g., 'user.name')
     * @param mixed $expectedValue Expected value at path
     * @throws MockAssertionException
     */
    public function assertRequestJsonPath(string $method, string $url, string $path, mixed $expectedValue): void
    {
        foreach ($this->getRequestHistory() as $request) {
            if (
                strtoupper($request->getMethod()) === strtoupper($method) &&
                $request->getUrl() === $url
            ) {
                $json = $request->getJson();

                if ($json !== null) {
                    $actualValue = $this->getJsonPath($json, $path);

                    if ($actualValue === $expectedValue) {
                        return;
                    }
                }
            }
        }

        throw new MockAssertionException(
            "Expected request with JSON path '{$path}' not found: {$method} {$url}"
        );
    }

    /**
     * Assert that a request was made with empty body.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @throws MockAssertionException
     */
    public function assertRequestWithEmptyBody(string $method, string $url): void
    {
        foreach ($this->getRequestHistory() as $request) {
            if (
                strtoupper($request->getMethod()) === strtoupper($method) &&
                $request->getUrl() === $url
            ) {
                $body = $request->getBody();

                if ($body === null || $body === '') {
                    return;
                }
            }
        }

        throw new MockAssertionException(
            "Expected request with empty body not found: {$method} {$url}"
        );
    }

    /**
     * Assert that a request has a non-empty body.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @throws MockAssertionException
     */
    public function assertRequestHasBody(string $method, string $url): void
    {
        foreach ($this->getRequestHistory() as $request) {
            if (
                strtoupper($request->getMethod()) === strtoupper($method) &&
                $request->getUrl() === $url
            ) {
                $body = $request->getBody();

                if ($body !== null && $body !== '') {
                    return;
                }
            }
        }

        throw new MockAssertionException(
            "Expected request with non-empty body not found: {$method} {$url}"
        );
    }

    /**
     * Assert that a request was made with JSON body.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @throws MockAssertionException
     */
    public function assertRequestIsJson(string $method, string $url): void
    {
        foreach ($this->getRequestHistory() as $request) {
            if (
                strtoupper($request->getMethod()) === strtoupper($method) &&
                $request->getUrl() === $url &&
                $request->isJson()
            ) {
                return;
            }
        }

        throw new MockAssertionException(
            "Expected request with JSON body not found: {$method} {$url}"
        );
    }

    /**
     * Assert that a request body matches a pattern.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param string $pattern Regular expression pattern
     * @throws MockAssertionException
     */
    public function assertRequestBodyMatches(string $method, string $url, string $pattern): void
    {
        foreach ($this->getRequestHistory() as $request) {
            if (
                strtoupper($request->getMethod()) === strtoupper($method) &&
                $request->getUrl() === $url
            ) {
                $body = $request->getBody();

                if ($body !== null && preg_match($pattern, $body) === 1) {
                    return;
                }
            }
        }

        throw new MockAssertionException(
            "Expected request body matching pattern not found: {$method} {$url}"
        );
    }

    /**
     * Check if an array contains all expected key-value pairs.
     *
     * @param array<mixed> $array Array to check
     * @param array<mixed> $expected Expected key-value pairs
     * @return bool
     */
    private function arrayContains(array $array, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $array)) {
                return false;
            }

            if (is_array($value) && is_array($array[$key])) {
                if (!$this->arrayContains($array[$key], $value)) {
                    return false;
                }
            } elseif ($array[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get value from JSON using dot notation path.
     *
     * @param array<mixed> $json JSON
     * * @param string $path Dot notation path
     * @return mixed
     */
    private function getJsonPath(array $json, string $path): mixed
    {
        $keys = explode('.', $path);
        $current = $json;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }
}
