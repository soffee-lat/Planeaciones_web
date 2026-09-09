<?php

namespace App\Data\AI;

final readonly class AuditResult
{
    /** @param list<AuditFinding> $findings */
    public function __construct(
        public bool $passed,
        public array $findings,
    ) {}

    /** @return array{schema_version:string,passed:bool,findings:list<array<string,string>>} */
    public function toArray(): array
    {
        return [
            'schema_version' => 'audit_result_v1',
            'passed' => $this->passed,
            'findings' => array_map(fn (AuditFinding $finding) => $finding->toArray(), $this->findings),
        ];
    }
}
