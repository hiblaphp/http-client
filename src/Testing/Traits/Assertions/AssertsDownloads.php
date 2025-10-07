<?php

namespace Hibla\Http\Testing\Traits\Assertions;

use Hibla\Http\Testing\Exceptions\MockAssertionException;
use Hibla\Http\Testing\Utilities\RecordedRequest;

trait AssertsDownloads
{
    /**
     * @return array<int, RecordedRequest>
     */
    abstract public function getRequestHistory(): array;

    /**
     * Assert that a download was made to a specific destination.
     *
     * @param string $url The URL that was downloaded
     * @param string $destination The expected destination path
     * @throws MockAssertionException
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

        throw new MockAssertionException(
            "Expected download not found: {$url} to {$destination}"
        );
    }

    /**
     * Assert that a download was made to any destination.
     *
     * @param string $url The URL that was downloaded
     * @throws MockAssertionException
     */
    public function assertDownloadMadeToUrl(string $url): void
    {
        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();
            
            if ($request->getUrl() === $url && isset($options['download'])) {
                return;
            }
        }

        throw new MockAssertionException("Expected download not found for URL: {$url}");
    }

    /**
     * Assert that a specific file was downloaded.
     *
     * @param string $destination The destination path
     * @throws MockAssertionException
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

        throw new MockAssertionException(
            "Expected file download not found: {$destination}"
        );
    }

    /**
     * Assert that a download was made with specific headers.
     *
     * @param string $url The URL that was downloaded
     * @param array<string, string> $expectedHeaders Expected request headers
     * @throws MockAssertionException
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

        throw new MockAssertionException(
            "Expected download with headers not found for URL: {$url}"
        );
    }

    /**
     * Assert that no downloads were made.
     *
     * @throws MockAssertionException
     */
    public function assertNoDownloadsMade(): void
    {
        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();
            
            if (isset($options['download'])) {
                $destination = $options['download'];
                $destinationStr = is_string($destination) ? $destination : 'unknown';
                
                throw new MockAssertionException(
                    "Expected no downloads, but at least one was made to: {$destinationStr}"
                );
            }
        }
    }

    /**
     * Assert a specific number of downloads were made.
     *
     * @param int $expected Expected number of downloads
     * @throws MockAssertionException
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
            throw new MockAssertionException(
                "Expected {$expected} downloads, but {$actual} were made"
            );
        }
    }

    /**
     * Assert that a file exists at the download destination.
     *
     * @param string $destination The destination path
     * @throws MockAssertionException
     */
    public function assertDownloadedFileExists(string $destination): void
    {
        $this->assertFileDownloaded($destination);

        if (!file_exists($destination)) {
            throw new MockAssertionException(
                "Download was recorded but file does not exist: {$destination}"
            );
        }
    }

    /**
     * Assert that a downloaded file has specific content.
     *
     * @param string $destination The destination path
     * @param string $expectedContent Expected file content
     * @throws MockAssertionException
     */
    public function assertDownloadedFileContains(string $destination, string $expectedContent): void
    {
        $this->assertDownloadedFileExists($destination);

        $actualContent = file_get_contents($destination);
        
        if ($actualContent === false) {
            throw new MockAssertionException(
                "Cannot read downloaded file: {$destination}"
            );
        }

        if ($actualContent !== $expectedContent) {
            throw new MockAssertionException(
                "Downloaded file content does not match expected content"
            );
        }
    }

    /**
     * Assert that a downloaded file contains a substring.
     *
     * @param string $destination The destination path
     * @param string $needle Substring to search for
     * @throws MockAssertionException
     */
    public function assertDownloadedFileContainsString(string $destination, string $needle): void
    {
        $this->assertDownloadedFileExists($destination);

        $actualContent = file_get_contents($destination);
        
        if ($actualContent === false) {
            throw new MockAssertionException(
                "Cannot read downloaded file: {$destination}"
            );
        }

        if (!str_contains($actualContent, $needle)) {
            throw new MockAssertionException(
                "Downloaded file does not contain expected string: {$needle}"
            );
        }
    }

    /**
     * Assert that a downloaded file size matches expected size.
     *
     * @param string $destination The destination path
     * @param int $expectedSize Expected file size in bytes
     * @throws MockAssertionException
     */
    public function assertDownloadedFileSize(string $destination, int $expectedSize): void
    {
        $this->assertDownloadedFileExists($destination);

        $actualSize = filesize($destination);
        
        if ($actualSize === false) {
            throw new MockAssertionException(
                "Cannot determine size of downloaded file: {$destination}"
            );
        }

        if ($actualSize !== $expectedSize) {
            throw new MockAssertionException(
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
     * @throws MockAssertionException
     */
    public function assertDownloadedFileSizeBetween(string $destination, int $minSize, int $maxSize): void
    {
        $this->assertDownloadedFileExists($destination);

        $actualSize = filesize($destination);
        
        if ($actualSize === false) {
            throw new MockAssertionException(
                "Cannot determine size of downloaded file: {$destination}"
            );
        }

        if ($actualSize < $minSize || $actualSize > $maxSize) {
            throw new MockAssertionException(
                "Downloaded file size {$actualSize} is not between {$minSize} and {$maxSize}"
            );
        }
    }

    /**
     * Assert that a download was made using a specific HTTP method.
     *
     * @param string $url The URL that was downloaded
     * @param string $method Expected HTTP method
     * @throws MockAssertionException
     */
    public function assertDownloadWithMethod(string $url, string $method): void
    {
        foreach ($this->getRequestHistory() as $request) {
            $options = $request->getOptions();
            
            if ($request->getUrl() === $url && 
                isset($options['download']) &&
                strtoupper($request->getMethod()) === strtoupper($method)) {
                return;
            }
        }

        throw new MockAssertionException(
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
            $destination = $options['download'] ?? 'unknown';
            $destinationStr = is_string($destination) ? $destination : 'unknown';
            
            echo "\n[{$index}] {$request->getMethod()} {$request->getUrl()}\n";
            echo "    Destination: {$destinationStr}\n";
            
            if (is_string($destination) && file_exists($destination)) {
                $size = filesize($destination);
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