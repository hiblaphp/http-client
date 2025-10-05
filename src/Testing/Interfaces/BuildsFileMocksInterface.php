<?php

namespace Hibla\Http\Testing\Interfaces;

interface BuildsFileMocksInterface
{
    /**
     * Mock a file download response.
     */
    public function downloadFile(string $content, ?string $filename = null, string $contentType = 'application/octet-stream'): static;

    /**
     * Mock a large file download with generated content.
     */
    public function downloadLargeFile(int $sizeInKB = 100, ?string $filename = null): static;
}
