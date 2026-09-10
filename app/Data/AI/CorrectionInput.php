<?php

namespace App\Data\AI;

use App\Data\Planning\CanonicalPlan;

final readonly class CorrectionInput
{
    /** @param list<string> $sectionKeys @param list<AuditFinding> $findings */
    public function __construct(
        public int $requestId,
        public int $inputRevision,
        public int $sourceVersionId,
        public int $sourceAuditExecutionId,
        public string $sourceKind,
        public ?int $sourceReviewId,
        public ?int $sourceCorrectionRequestId,
        public int $correctionRound,
        public CanonicalPlan $canonicalPlan,
        public array $sectionKeys,
        public array $findings,
        public int $promptVersionId,
        public string $correlationId,
        public string $operationKey,
    ) {}
}
