<?php

namespace App\Services\AI;

use App\Data\Planning\CanonicalPlan;
use App\Exceptions\AiContractException;

class CanonicalPlanValidator
{
    public const SCHEMA_VERSION = 'canonical_plan_v1';
    public const ADAPTIVE_SCHEMA_VERSION = 'canonical_adaptive_plan_v1';

    public function __construct(private JsonSchemaSubsetValidator $schemaValidator) {}

    /** @param array<string,mixed> $payload */
    public function validate(array $payload): CanonicalPlan
    {
        $schemaVersion = (string) ($payload['schema_version'] ?? '');
        $schemaPath = match ($schemaVersion) {
            self::SCHEMA_VERSION => resource_path('schemas/ai/canonical_plan_v1.schema.json'),
            self::ADAPTIVE_SCHEMA_VERSION => resource_path('schemas/ai/canonical_adaptive_plan_v1.schema.json'),
            default => throw new AiContractException('CANONICAL_SCHEMA_VERSION_UNSUPPORTED', '$.schema_version', $schemaVersion),
        };

        $schema = json_decode(file_get_contents($schemaPath), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($schema)) {
            throw new AiContractException('AI_SCHEMA_INVALID', '$', basename($schemaPath));
        }

        $basePayload = $payload;
        unset($basePayload['custom']);
        $this->schemaValidator->validate($basePayload, $schema);
        $this->validateCustom($payload['custom'] ?? null);
        if ($schemaVersion === self::ADAPTIVE_SCHEMA_VERSION) {
            $this->validateTemplateFields($payload['template_fields'] ?? null);
        }

        return new CanonicalPlan($payload);
    }

    private function validateTemplateFields(mixed $fields): void
    {
        if (! is_array($fields) || array_is_list($fields)) {
            throw new AiContractException('CANONICAL_TEMPLATE_FIELDS_OBJECT_REQUIRED', '$.template_fields');
        }
        foreach ($fields as $path => $value) {
            if (! is_string($path)
                || preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*$/', $path) !== 1) {
                throw new AiContractException('CANONICAL_TEMPLATE_FIELD_PATH_INVALID', '$.template_fields.' . (string) $path);
            }
            $nodes = 0;
            $this->validateFlexibleValue($value, '$.template_fields.' . $path, 0, $nodes, 'CANONICAL_TEMPLATE_FIELD');
        }
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
            $this->validateFlexibleValue($value, '$.custom.' . $key, 0, $nodes, 'CANONICAL_CUSTOM');
        }
    }

    private function validateFlexibleValue(mixed $value, string $path, int $depth, int &$nodes, string $prefix): void
    {
        $nodes++;
        if ($nodes > 1000) {
            throw new AiContractException($prefix . '_TOO_LARGE', $path);
        }
        if ($depth > 7) {
            throw new AiContractException($prefix . '_TOO_DEEP', $path);
        }

        if (is_string($value)) {
            if (trim($value) === '') {
                throw new AiContractException($prefix . '_VALUE_INVALID', $path);
            }

            return;
        }

        if (! is_array($value) || $value === []) {
            throw new AiContractException($prefix . '_VALUE_INVALID', $path);
        }

        if (array_is_list($value)) {
            foreach ($value as $index => $item) {
                $this->validateFlexibleValue($item, $path . '[' . $index . ']', $depth + 1, $nodes, $prefix);
            }

            return;
        }

        foreach ($value as $key => $item) {
            if ($prefix === 'CANONICAL_CUSTOM') {
                $this->assertCustomKey((string) $key, $path);
            }
            $this->validateFlexibleValue($item, $path . '.' . (string) $key, $depth + 1, $nodes, $prefix);
        }
    }

    private function assertCustomKey(string $key, string $path): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $key) !== 1) {
            throw new AiContractException('CANONICAL_CUSTOM_KEY_INVALID', $path . '.' . $key);
        }
    }
}
