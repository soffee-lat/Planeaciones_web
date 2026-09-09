<?php

namespace App\Data\AI;

final readonly class AuditFinding
{
    public function __construct(
        public string $code,
        public string $severity,
        public string $jsonPath,
        public string $explanation,
        public string $expectedCorrection,
    ) {}

    /** @return array<string,string> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'json_path' => $this->jsonPath,
            'explanation' => $this->explanation,
            'expected_correction' => $this->expectedCorrection,
        ];
    }
}
