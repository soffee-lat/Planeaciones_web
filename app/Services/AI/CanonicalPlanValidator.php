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

        $basePayload = $payload;
        unset($basePayload['custom']);
        $this->schemaValidator->validate($basePayload, $schema);
        $this->validateCustom($payload['custom'] ?? null);

        return new CanonicalPlan($payload);
    }

    private function validateCustom(mixed $custom): void
    {
        if ($custom === null) {
            return;
        }
        if (! is_array($custom) || array_is_list($custom)) {
            throw new AiContractException('CANONICAL_CUSTOM_OBJECT_REQUIRED', '$.custom');
        }

        foreach ($custom as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $key) !== 1) {
                throw new AiContractException('CANONICAL_CUSTOM_KEY_INVALID', '$.custom.' . $key);
            }
            if (is_string($value) && trim($value) !== '') {
                continue;
            }
            if (is_array($value) && array_is_list($value) && $value !== []) {
                $valid = true;
                foreach ($value as $item) {
                    if (! is_string($item) || trim($item) === '') {
                        $valid = false;
                        break;
                    }
                }
                if ($valid) {
                    continue;
                }
            }
            throw new AiContractException('CANONICAL_CUSTOM_VALUE_INVALID', '$.custom.' . $key);
        }
    }
}
