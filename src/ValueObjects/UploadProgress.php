<?php

declare(strict_types=1);

namespace Hibla\HttpClient\ValueObjects;

final readonly class UploadProgress
{
    public float $percent;

    public function __construct(
        public int $total,
        public int $uploaded,
    ) {
        $this->percent = $total > 0
            ? round(($uploaded / $total) * 100, 2)
            : 0.0;
    }

    public function isComplete(): bool
    {
        return $this->total > 0 && $this->uploaded >= $this->total;
    }
}
