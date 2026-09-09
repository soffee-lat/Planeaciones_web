<?php

namespace App\Data\AI;

final readonly class AuditResult
{
    /** @param list<AuditFinding> $findings */
    public function __construct(
        public bool $passed,
        public array $findings,
    ) {}
}
