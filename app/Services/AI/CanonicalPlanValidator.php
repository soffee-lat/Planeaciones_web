<?php

namespace App\Services\AI;

use App\Data\Planning\CanonicalPlan;
use App\Exceptions\AiContractException;

class CanonicalPlanValidator
{
    public const SCHEMA_VERSION = 'canonical_plan_v1';

    public function __construct(private JsonSchemaSubsetValidator $schemaValidator) {}

    /** @param array<string,mixed> $payload */
    public function validate(array $payload): CanonicalPlan
    {
        $schema = json_decode(
            file_get_contents(resource_path('schemas/ai/canonical_plan_v1.schema.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (! is_array($schema)) {
            throw new AiContractException('AI_SCHEMA_INVALID', '$', 'canonical_plan_v1');
        }

        $this->schemaValidator->validate($payload, $schema);

        return new CanonicalPlan($payload);
    }
}
