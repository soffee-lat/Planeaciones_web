<?php

namespace App\Data\AI;

final readonly class DocumentInput
{
    /** @param array<string,mixed> $manifest */
    public function __construct(
        public int $formatVersionId,
        public array $manifest,
        public int $promptVersionId,
        public string $correlationId,
        public string $operationKey,
    ) {}
}
