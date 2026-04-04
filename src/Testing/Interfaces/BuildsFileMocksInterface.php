<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Interfaces;

interface BuildsFileMocksInterface
{
    /**
     * Mock a file download response.
     */
    public function downloadFile(
        string $content,
        ?string $filename = null,
        string $contentType = 'application/octet-stream',
        float $delayPerChunk = 0,
        float $jitter = 0
    ): static;

    /**
     * Mock a large file download with generated content.
     */
    public function downloadLargeFile(
        int $sizeInKB = 100,
        ?string $filename = null,
        float $delayPerChunk = 0,
        float $jitter = 0
    ): static;
}
