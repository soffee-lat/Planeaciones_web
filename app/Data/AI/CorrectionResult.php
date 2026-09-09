<?php

namespace App\Data\AI;

final readonly class CorrectionResult
{
    /** @param array<string,mixed> $patch */
    public function __construct(
        public int $sourceVersionId,
        public array $patch,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 'correction_result_v1',
            'source_version_id' => $this->sourceVersionId,
            'patch' => $this->patch,
        ];
    }
}
