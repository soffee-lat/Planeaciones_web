<?php

namespace App\Data\AI;

use App\Data\Planning\CanonicalPlan;

final readonly class AuditInput
{
    public function __construct(
        public int $requestId,
        public int $inputRevision,
        public CanonicalPlan $canonicalPlan,
        public int $promptVersionId,
        public string $correlationId,
        public string $operationKey,
    ) {}
}
