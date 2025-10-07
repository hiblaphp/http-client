<?php

namespace Hibla\HttpClient\Testing\Interfaces;

use Hibla\HttpClient\Testing\Exceptions\MockAssertionException;
use Hibla\HttpClient\Testing\Utilities\RecordedRequest;

interface AssertsDownloadsInterface
{
    /**
     * Assert that a download was made to a specific destination.
     *
     * @param string $url The URL that was downloaded
     * @param string $destination The expected destination path
     * @throws MockAssertionException
     */
    public function assertDownloadMade(string $url, string $destination): void;

    /**
     * Assert that a download was made to any destination.
     *
     * @param string $url The URL that was downloaded
     * @throws MockAssertionException
     */
    public function assertDownloadMadeToUrl(string $url): void;

    /**
     * Assert that a specific file was downloaded.
     *
     * @param string $destination The destination path
     * @throws MockAssertionException
     */
    public function assertFileDownloaded(string $destination): void;

    /**
     * Assert that a download was made with specific headers.
     *
     * @param string $url The URL that was downloaded
     * @param array<string, string> $expectedHeaders Expected request headers
     * @throws MockAssertionException
     */
    public function assertDownloadWithHeaders(string $url, array $expectedHeaders): void;

    /**
     * Assert that no downloads were made.
     *
     * @throws MockAssertionException
     */
    public function assertNoDownloadsMade(): void;

    /**
     * Assert a specific number of downloads were made.
     *
     * @param int $expected Expected number of downloads
     * @throws MockAssertionException
     */
    public function assertDownloadCount(int $expected): void;

    /**
     * Assert that a file exists at the download destination.
     *
     * @param string $destination The destination path
     * @throws MockAssertionException
     */
    public function assertDownloadedFileExists(string $destination): void;

    /**
     * Assert that a downloaded file has specific content.
     *
     * @param string $destination The destination path
     * @param string $expectedContent Expected file content
     * @throws MockAssertionException
     */
    public function assertDownloadedFileContains(string $destination, string $expectedContent): void;

    /**
     * Assert that a downloaded file contains a substring.
     *
     * @param string $destination The destination path
     * @param string $needle Substring to search for
     * @throws MockAssertionException
     */
    public function assertDownloadedFileContainsString(string $destination, string $needle): void;

    /**
     * Assert that a downloaded file size matches expected size.
     *
     * @param string $destination The destination path
     * @param int $expectedSize Expected file size in bytes
     * @throws MockAssertionException
     */
    public function assertDownloadedFileSize(string $destination, int $expectedSize): void;

    /**
     * Assert that a downloaded file size is within a range.
     *
     * @param string $destination The destination path
     * @param int $minSize Minimum size in bytes
     * @param int $maxSize Maximum size in bytes
     * @throws MockAssertionException
     */
    public function assertDownloadedFileSizeBetween(string $destination, int $minSize, int $maxSize): void;

    /**
     * Assert that a download was made using a specific HTTP method.
     *
     * @param string $url The URL that was downloaded
     * @param string $method Expected HTTP method
     * @throws MockAssertionException
     */
    public function assertDownloadWithMethod(string $url, string $method): void;

    /**
     * Get all download requests from history.
     *
     * @return array<int, RecordedRequest>
     */
    public function getDownloadRequests(): array;

    /**
     * Get the last download request.
     *
     * @return RecordedRequest|null
     */
    public function getLastDownload(): ?RecordedRequest;

    /**
     * Get the first download request.
     *
     * @return RecordedRequest|null
     */
    public function getFirstDownload(): ?RecordedRequest;

    /**
     * Get download destination for a specific URL.
     *
     * @param string $url The URL
     * @return string|null The destination path or null
     */
    public function getDownloadDestination(string $url): ?string;

    /**
     * Dump information about all downloads for debugging.
     *
     * @return void
     */
    public function dumpDownloads(): void;

    /**
     * Dump detailed information about the last download.
     *
     * @return void
     */
    public function dumpLastDownload(): void;
}