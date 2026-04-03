<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Traits\Assertions;

use Hibla\HttpClient\Testing\Utilities\RecordedRequest;

trait AssertsUploads
{
    use AssertionHandler;

    abstract public function getRequestHistory(): array;

    abstract protected function getRequestMatcher();

    public function assertUploadMade(string $url, string $source): void
    {
        $this->registerAssertion();
        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();
            if ($this->getRequestMatcher()->matchesRequest($request, '*', $url) && isset($options['upload'])) {
                if (is_string($options['upload']) && $options['upload'] === $source) {
                    return;
                }
            }
        }
        $this->failAssertion("Expected upload not found: {$source} to {$url}");
    }

    public function assertUploadMadeToUrl(string $url): void
    {
        $this->registerAssertion();
        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();
            if ($this->getRequestMatcher()->matchesRequest($request, '*', $url) && isset($options['upload'])) {
                return;
            }
        }
        $this->failAssertion("Expected upload not found for URL: {$url}");
    }

    public function assertNoUploadsMade(): void
    {
        $this->registerAssertion();
        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();
            if (isset($options['upload'])) {
                $source = is_string($options['upload']) ? $options['upload'] : 'unknown';
                $this->failAssertion("Expected no uploads, but at least one was made from: {$source}");
            }
        }
    }

    public function assertUploadCount(int $expected): void
    {
        $this->registerAssertion();
        $actual = count($this->getUploadRequests());
        if ($actual !== $expected) {
            $this->failAssertion("Expected {$expected} uploads, but {$actual} were made");
        }
    }

    public function getUploadRequests(): array
    {
        $uploads = [];
        foreach ($this->getRequestHistory() as $request) {
            if (isset($request->getOptions()['upload'])) {
                $uploads[] = $request;
            }
        }

        return $uploads;
    }

    public function getLastUpload(): ?RecordedRequest
    {
        $uploads = $this->getUploadRequests();

        return $uploads === [] ? null : end($uploads);
    }
}
