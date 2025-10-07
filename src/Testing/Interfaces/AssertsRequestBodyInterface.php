<?php

namespace Hibla\HttpClient\Testing\Interfaces;

use Hibla\HttpClient\Testing\Exceptions\MockAssertionException;

interface AssertsRequestBodyInterface
{
    /**
     * Assert that a request was made with specific body content.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param string $expectedBody Expected body content
     * @throws MockAssertionException
     */
    public function assertRequestWithBody(string $method, string $url, string $expectedBody): void;

    /**
     * Assert that a request was made with body containing a string.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param string $needle String to search for
     * @throws MockAssertionException
     */
    public function assertRequestBodyContains(string $method, string $url, string $needle): void;

    /**
     * Assert that a request was made with JSON body.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param array<mixed> $expectedJson Expected JSON data
     * @throws MockAssertionException
     */
    public function assertRequestWithJson(string $method, string $url, array $expectedJson): void;

    /**
     * Assert that a request was made with JSON containing specific keys.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param array<mixed> $expectedKeys Expected keys and values
     * @throws MockAssertionException
     */
    public function assertRequestJsonContains(string $method, string $url, array $expectedKeys): void;

    /**
     * Assert that a request was made with a JSON path value.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param string $path Dot-notation path (e.g., 'user.name')
     * @param mixed $expectedValue Expected value at path
     * @throws MockAssertionException
     */
    public function assertRequestJsonPath(string $method, string $url, string $path, mixed $expectedValue): void;

    /**
     * Assert that a request was made with empty body.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @throws MockAssertionException
     */
    public function assertRequestWithEmptyBody(string $method, string $url): void;

    /**
     * Assert that a request has a non-empty body.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @throws MockAssertionException
     */
    public function assertRequestHasBody(string $method, string $url): void;

    /**
     * Assert that a request was made with JSON body.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @throws MockAssertionException
     */
    public function assertRequestIsJson(string $method, string $url): void;

    /**
     * Assert that a request body matches a pattern.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param string $pattern Regular expression pattern
     * @throws MockAssertionException
     */
    public function assertRequestBodyMatches(string $method, string $url, string $pattern): void;
}