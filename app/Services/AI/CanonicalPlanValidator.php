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

        $nodes = 0;
        foreach ($custom as $key => $value) {
            $this->assertCustomKey((string) $key, '$.custom');
            $this->validateCustomValue($value, '$.custom.' . $key, 0, $nodes);
        }
    }

    private function validateCustomValue(mixed $value, string $path, int $depth, int &$nodes): void
    {
        $nodes++;
        if ($nodes > 500) {
            throw new AiContractException('CANONICAL_CUSTOM_TOO_LARGE', $path);
        }
        if ($depth > 5) {
            throw new AiContractException('CANONICAL_CUSTOM_TOO_DEEP', $path);
        }

        if (is_string($value)) {
            if (trim($value) === '') {
                throw new AiContractException('CANONICAL_CUSTOM_VALUE_INVALID', $path);
            }
            return;
        }

        if (! is_array($value) || $value === []) {
            throw new AiContractException('CANONICAL_CUSTOM_VALUE_INVALID', $path);
        }

        if (array_is_list($value)) {
            foreach ($value as $index => $item) {
                $this->validateCustomValue($item, $path . '[' . $index . ']', $depth + 1, $nodes);
            }
            return;
        }

        foreach ($value as $key => $item) {
            $this->assertCustomKey((string) $key, $path);
            $this->validateCustomValue($item, $path . '.' . $key, $depth + 1, $nodes);
        }
    }

    private function assertCustomKey(string $key, string $path): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $key) !== 1) {
            throw new AiContractException('CANONICAL_CUSTOM_KEY_INVALID', $path . '.' . $key);
        }
    }
}
