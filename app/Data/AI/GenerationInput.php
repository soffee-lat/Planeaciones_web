<?php

namespace App\Data\AI;

final readonly class GenerationInput
{
    /**
     * @param array<string,mixed> $inputSnapshot
     * @param array<string,mixed> $commercialSnapshot
     * @param list<array<string,mixed>> $segments
     * @param array<string,mixed> $inputManifest
     */
    public function __construct(
        public int $requestId,
        public int $inputRevision,
        public array $inputSnapshot,
        public array $commercialSnapshot,
        public int $planningUnits,
        public array $segments,
        public array $inputManifest,
        public int $promptVersionId,
        public string $outputSchemaVersion,
        public string $correlationId,
        public string $operationKey,
    ) {}
}
