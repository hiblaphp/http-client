<?php

namespace Hibla\HttpClient\Testing\Traits\RequestBuilder;

trait BuildsFileMocks
{
    abstract protected function getRequest();

    /**
     * Mock a file download response.
     */
    public function downloadFile(string $content, ?string $filename = null, string $contentType = 'application/octet-stream'): static
    {
        $this->getRequest()->setBody($content);
        $this->getRequest()->addResponseHeader('Content-Type', $contentType);
        $this->getRequest()->addResponseHeader('Content-Length', (string) strlen($content));

        if ($filename !== null) {
            $this->getRequest()->addResponseHeader('Content-Disposition', "attachment; filename=\"{$filename}\"");
        }

        return $this;
    }

    /**
     * Mock a large file download with generated content.
     */
    public function downloadLargeFile(int $sizeInKB = 100, ?string $filename = null): static
    {
        $content = str_repeat('MOCK_FILE_DATA_', $sizeInKB * 64);

        return $this->downloadFile($content, $filename, 'application/octet-stream');
    }
}
