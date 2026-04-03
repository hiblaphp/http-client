<?php

declare(strict_types=1);

namespace Hibla\HttpClient\Testing\Interfaces;

use Hibla\HttpClient\Testing\Utilities\RecordedRequest;

interface AssertsUploadsInterface
{
    public function assertUploadMade(string $url, string $source): void;

    public function assertUploadMadeToUrl(string $url): void;

    public function assertNoUploadsMade(): void;

    public function assertUploadCount(int $expected): void;

    public function getUploadRequests(): array;

    public function getLastUpload(): ?RecordedRequest;
}
