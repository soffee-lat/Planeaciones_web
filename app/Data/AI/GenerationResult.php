<?php

namespace App\Data\AI;

use App\Data\Planning\GeneratedPlanDraft;

final readonly class GenerationResult
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public GeneratedPlanDraft $draft,
        public array $metadata = [],
    ) {}
}
