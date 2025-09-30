<?php

namespace Hibla\Http\Testing\Utilities;

class RequestMatcher
{
    public function findMatchingMock(array $mocks, string $method, string $url, array $options): ?array
    {
        foreach ($mocks as $index => $mock) {
            if ($mock->matches($method, $url, $options)) {
                return ['mock' => $mock, 'index' => $index];
            }
        }

        return null;
    }

    public function matchesRequest(RecordedRequest $request, string $method, string $url, array $options = []): bool
    {
        if ($request->method !== $method && $method !== '*') {
            return false;
        }

        if (! fnmatch($url, $request->url) && $request->url !== $url) {
            return false;
        }

        return true;
    }
}
