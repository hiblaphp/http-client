<?php

declare(strict_types=1);

namespace Hibla\HttpClient\ValueObjects;

final readonly class DownloadProgress
{
    public float $percent;

    public function __construct(
        public int $total,
        public int $downloaded,
    ) {
        $this->percent = $total > 0
            ? round(($downloaded / $total) * 100, 2)
            : 0.0;
    }

    public function isComplete(): bool
    {
        return $this->total > 0 && $this->downloaded >= $this->total;
    }
}
