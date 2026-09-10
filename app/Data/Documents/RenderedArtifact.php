<?php

namespace App\Data\Documents;

use App\Enums\DocumentOutputFormat;

final readonly class RenderedArtifact
{
    public function __construct(
        public DocumentOutputFormat $format,
        public string $mimeType,
        public string $extension,
        public string $bytes,
    ) {}

    public function sha256(): string
    {
        return hash('sha256', $this->bytes);
    }

    public function sizeBytes(): int
    {
        return strlen($this->bytes);
    }
}
