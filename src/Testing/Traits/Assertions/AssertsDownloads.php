<?php

namespace Hibla\HttpClient\Testing\Traits\Assertions;

use Hibla\HttpClient\Testing\Utilities\RecordedRequest;

trait AssertsDownloads
{
    use AssertionHandler;

    /**
     * @return array<int, RecordedRequest>
     */
    abstract public function getRequestHistory(): array;

    /**
     * Assert that a download was made to a specific destination.
     *
     * @param string $url The URL that was downloaded
     * @param string $destination The expected destination path
     */
    public function assertDownloadMade(string $url, string $destination): void
    {
        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();

            if ($request->getUrl() === $url && isset($options['download'])) {
                $downloadDest = $options['download'];

                if (is_string($downloadDest) && $downloadDest === $destination) {
                    return;
                }
            }
        }

        $this->failAssertion(
            "Expected download not found: {$url} to {$destination}"
        );
    }

    /**
     * Assert that a download was made to any destination.
     *
     * @param string $url The URL that was downloaded
     */
    public function assertDownloadMadeToUrl(string $url): void
    {
        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();

            if ($request->getUrl() === $url && isset($options['download'])) {
                return;
            }
        }

        $this->failAssertion("Expected download not found for URL: {$url}");
    }

    /**
     * Assert that a specific file was downloaded.
     *
     * @param string $destination The destination path
     */
    public function assertFileDownloaded(string $destination): void
    {
        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();

            if (isset($options['download'])) {
                $downloadDest = $options['download'];

                if (is_string($downloadDest) && $downloadDest === $destination) {
                    return;
                }
            }
        }

        $this->failAssertion(
            "Expected file download not found: {$destination}"
        );
    }

    /**
     * Assert that a download was made with specific headers.
     *
     * @param string $url The URL that was downloaded
     * @param array<string, string> $expectedHeaders Expected request headers
     */
    public function assertDownloadWithHeaders(string $url, array $expectedHeaders): void
    {
        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();

            if ($request->getUrl() === $url && isset($options['download'])) {
                $matches = true;

                foreach ($expectedHeaders as $name => $value) {
                    $headerValue = $request->getHeader($name);

                    if ($headerValue === null || $headerValue !== $value) {
                        $matches = false;
                        break;
                    }
                }

                if ($matches) {
                    return;
                }
            }
        }

        $this->failAssertion(
            "Expected download with headers not found for URL: {$url}"
        );
    }

    /**
     * Assert that no downloads were made.
     */
    public function assertNoDownloadsMade(): void
    {
        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();

            if (isset($options['download'])) {
                $destination = $options['download'];
                $destinationStr = is_string($destination) ? $destination : 'unknown';

                $this->failAssertion(
                    "Expected no downloads, but at least one was made to: {$destinationStr}"
                );
            }
        }
    }

    /**
     * Assert a specific number of downloads were made.
     *
     * @param int $expected Expected number of downloads
     */
    public function assertDownloadCount(int $expected): void
    {
        $actual = 0;

        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();

            if (isset($options['download'])) {
                $actual++;
            }
        }

        if ($actual !== $expected) {
            $this->failAssertion(
                "Expected {$expected} downloads, but {$actual} were made"
            );
        }
    }

    /**
     * Assert that a file exists at the download destination.
     *
     * @param string $destination The destination path
     */
    public function assertDownloadedFileExists(string $destination): void
    {
        $this->assertFileDownloaded($destination);

        if (!file_exists($destination)) {
            $this->failAssertion(
                "Download was recorded but file does not exist: {$destination}"
            );
        }
    }

    /**
     * Assert that a downloaded file has specific content.
     *
     * @param string $destination The destination path
     * @param string $expectedContent Expected file content
     */
    public function assertDownloadedFileContains(string $destination, string $expectedContent): void
    {
        $this->assertDownloadedFileExists($destination);

        $actualContent = file_get_contents($destination);

        if ($actualContent === false) {
            $this->failAssertion(
                "Cannot read downloaded file: {$destination}"
            );
        }

        if ($actualContent !== $expectedContent) {
            $this->failAssertion(
                "Downloaded file content does not match expected content"
            );
        }
    }

    /**
     * Assert that a downloaded file contains a substring.
     *
     * @param string $destination The destination path
     * @param string $needle Substring to search for
     */
    public function assertDownloadedFileContainsString(string $destination, string $needle): void
    {
        $this->assertDownloadedFileExists($destination);

        $actualContent = file_get_contents($destination);

        if ($actualContent === false) {
            $this->failAssertion(
                "Cannot read downloaded file: {$destination}"
            );
        }

        if (!str_contains($actualContent, $needle)) {
            $this->failAssertion(
                "Downloaded file does not contain expected string: {$needle}"
            );
        }
    }

    /**
     * Assert that a downloaded file size matches expected size.
     *
     * @param string $destination The destination path
     * @param int $expectedSize Expected file size in bytes
     */
    public function assertDownloadedFileSize(string $destination, int $expectedSize): void
    {
        $this->assertDownloadedFileExists($destination);

        $actualSize = filesize($destination);

        if ($actualSize === false) {
            $this->failAssertion(
                "Cannot determine size of downloaded file: {$destination}"
            );
        }

        if ($actualSize !== $expectedSize) {
            $this->failAssertion(
                "Downloaded file size {$actualSize} does not match expected size {$expectedSize}"
            );
        }
    }

    /**
     * Assert that a downloaded file size is within a range.
     *
     * @param string $destination The destination path
     * @param int $minSize Minimum size in bytes
     * @param int $maxSize Maximum size in bytes
     */
    public function assertDownloadedFileSizeBetween(string $destination, int $minSize, int $maxSize): void
    {
        $this->assertDownloadedFileExists($destination);

        $actualSize = filesize($destination);

        if ($actualSize === false) {
            $this->failAssertion(
                "Cannot determine size of downloaded file: {$destination}"
            );
        }

        if ($actualSize < $minSize || $actualSize > $maxSize) {
            $this->failAssertion(
                "Downloaded file size {$actualSize} is not between {$minSize} and {$maxSize}"
            );
        }
    }

    /**
     * Assert that a download was made using a specific HTTP method.
     *
     * @param string $url The URL that was downloaded
     * @param string $method Expected HTTP method
     */
    public function assertDownloadWithMethod(string $url, string $method): void
    {
        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();

            if (
                $request->getUrl() === $url &&
                isset($options['download']) &&
                strtoupper($request->getMethod()) === strtoupper($method)
            ) {
                return;
            }
        }

        $this->failAssertion(
            "Expected download with method {$method} not found for URL: {$url}"
        );
    }

    /**
     * Get all download requests from history.
     *
     * @return array<int, RecordedRequest>
     */
    public function getDownloadRequests(): array
    {
        $downloads = [];

        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();

            if (isset($options['download'])) {
                $downloads[] = $request;
            }
        }

        return $downloads;
    }

    /**
     * Get the last download request.
     *
     * @return RecordedRequest|null
     */
    public function getLastDownload(): ?RecordedRequest
    {
        $downloads = $this->getDownloadRequests();

        if ($downloads === []) {
            return null;
        }

        return $downloads[count($downloads) - 1];
    }

    /**
     * Get the first download request.
     *
     * @return RecordedRequest|null
     */
    public function getFirstDownload(): ?RecordedRequest
    {
        $downloads = $this->getDownloadRequests();

        if ($downloads === []) {
            return null;
        }

        return $downloads[0];
    }

    /**
     * Get download destination for a specific URL.
     *
     * @param string $url The URL
     * @return string|null The destination path or null
     */
    public function getDownloadDestination(string $url): ?string
    {
        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();

            if ($request->getUrl() === $url && isset($options['download'])) {
                /** @var mixed $destination */
                $destination = $options['download'];
                return is_string($destination) ? $destination : null;
            }
        }

        return null;
    }

    /**
     * Dump information about all downloads for debugging.
     *
     * @return void
     */
    public function dumpDownloads(): void
    {
        $downloads = $this->getDownloadRequests();

        if ($downloads === []) {
            echo "No downloads recorded\n";
            return;
        }

        echo "=== Downloads (" . count($downloads) . ") ===\n";

        foreach ($downloads as $index => $request) {
            $options = $request->getOptions();
            /** @var mixed $destination */
            $destination = $options['download'] ?? 'unknown';
            $destinationStr = is_string($destination) ? $destination : 'unknown';

            echo "\n[{$index}] {$request->getMethod()} {$request->getUrl()}\n";
            echo "    Destination: {$destinationStr}\n";

            if (isset($options['download']) && is_string($options['download']) && file_exists($destinationStr)) {
                $size = filesize($destinationStr);
                $sizeStr = $size !== false ? $size . " bytes" : "unknown";
                echo "    File exists: Yes\n";
                echo "    File size: {$sizeStr}\n";
            } else {
                echo "    File exists: No\n";
            }

            $headers = $request->getHeaders();
            if ($headers !== []) {
                echo "    Headers:\n";
                foreach ($headers as $name => $value) {
                    $displayValue = is_array($value) ? implode(', ', $value) : $value;
                    echo "      {$name}: {$displayValue}\n";
                }
            }
        }

        echo "===================\n";
    }

    /**
     * Dump detailed information about the last download.
     *
     * @return void
     */
    public function dumpLastDownload(): void
    {
        $download = $this->getLastDownload();

        if ($download === null) {
            echo "No downloads recorded\n";
            return;
        }

        $options = $download->getOptions();
        /** @var mixed $destination */
        $destination = $options['download'] ?? 'unknown';
        $destinationStr = is_string($destination) ? $destination : 'unknown';

        echo "=== Last Download ===\n";
        echo "Method: {$download->getMethod()}\n";
        echo "URL: {$download->getUrl()}\n";
        echo "Destination: {$destinationStr}\n";

        if (is_string($destination) && file_exists($destination)) {
            $size = filesize($destination);
            $sizeStr = $size !== false ? $size . " bytes" : "unknown";
            echo "File exists: Yes\n";
            echo "File size: {$sizeStr}\n";
        } else {
            echo "File exists: No\n";
        }

        echo "\nHeaders:\n";
        foreach ($download->getHeaders() as $name => $value) {
            $displayValue = is_array($value) ? implode(', ', $value) : $value;
            echo "  {$name}: {$displayValue}\n";
        }

        echo "===================\n";
    }
}