<?php

namespace App\Data\AI;

final readonly class CorrectionResult
{
    /** @param array<string,mixed> $patch @param array<string,mixed> $metadata */
    public function __construct(
        public array $patch,
        public array $metadata = [],
    ) {}
}
