<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Traits\RequestBuilder;

trait BuildsFileMocks
{
    abstract protected function getRequest();

    /**
     * Mock a file download response.
     */
    public function downloadFile(
        string $content,
        ?string $filename = null,
        string $contentType = 'application/octet-stream',
        float $delayPerChunk = 0,
        float $jitter = 0
    ): static {
        $request = $this->getRequest();
        $request->setBody($content);
        $request->addResponseHeader('Content-Type', $contentType);
        $request->addResponseHeader('Content-Length', (string) strlen($content));

        $request->setChunkDelay($delayPerChunk);
        $request->setChunkJitter($jitter);

        if ($filename !== null) {
            $request->addResponseHeader('Content-Disposition', "attachment; filename=\"{$filename}\"");
        }

        return $this;
    }

   /**
     * Mock a large file download with generated content.
     */
    public function downloadLargeFile(
        int $sizeInKB = 100, 
        ?string $filename = null,
        float $delayPerChunk = 0,
        float $jitter = 0
    ): static {
        $content = str_repeat('MOCK_FILE_DATA__', $sizeInKB * 64);

        return $this->downloadFile($content, $filename, 'application/octet-stream', $delayPerChunk, $jitter);
    }
}
