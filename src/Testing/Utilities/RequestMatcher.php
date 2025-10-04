<?php

namespace Hibla\Http\Testing\Utilities;

use Hibla\Http\Testing\MockedRequest;

class RequestMatcher
{
    /**
     * @param array<int, MockedRequest> $mocks
     * @param array<int, mixed> $options
     * @return array{mock: MockedRequest, index: int}|null
     */
    public function findMatchingMock(array $mocks, string $method, string $url, array $options): ?array
    {
        foreach ($mocks as $index => $mock) {
            if ($mock->matches($method, $url, $options)) {
                return ['mock' => $mock, 'index' => $index];
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $options
     */
    public function matchesRequest(RecordedRequest $request, string $method, string $url, array $options = []): bool
    {
        if ($request->method !== $method && $method !== '*') {
            return false;
        }

        if (!$this->urlMatches($url, $request->url)) {
            return false;
        }

        return true;
    }

    /**
     * Check if URL matches the pattern, with lenient trailing slash handling
     */
    private function urlMatches(string $pattern, string $url): bool
    {
        if (fnmatch($pattern, $url)) {
            return true;
        }

        $normalizedPattern = rtrim($pattern, '/');
        $normalizedUrl = rtrim($url, '/');

        if (fnmatch($normalizedPattern, $normalizedUrl)) {
            return true;
        }

        if (fnmatch($normalizedPattern . '/', $normalizedUrl . '/')) {
            return true;
        }

        return false;
    }
}