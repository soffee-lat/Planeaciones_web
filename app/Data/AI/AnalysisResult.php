<?php

namespace App\Data\AI;

final readonly class AnalysisResult
{
    /** @param array<string,mixed> $analysis @param array<string,mixed> $metadata */
    public function __construct(
        public array $analysis,
        public array $metadata = [],
    ) {}
}
