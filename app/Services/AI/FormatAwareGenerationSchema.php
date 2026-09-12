<?php

namespace App\Services\AI;

final class FormatAwareGenerationSchema
{
    public const ADAPTIVE_CONTRACT_VERSION = 'adaptive_template_generation_v1';

    /**
     * Para mappings v1/v2 conserva el contrato histórico y sólo añade custom.*.
     * Para mappings v3 construye un contrato nuevo desde el formato confirmado:
     * núcleo pedagógico mínimo + campos AI explícitamente requeridos por el DOCX.
     *
     * @param array<string,mixed> $baseSchema
     * @param array<string,mixed> $formatContext
     * @return array<string,mixed>
     */
    public function extend(array $baseSchema, array $formatContext): array
    {
        if ((int) ($formatContext['schema_version'] ?? 0) >= 3
            && ($formatContext['generation_contract'] ?? null) === self::ADAPTIVE_CONTRACT_VERSION) {
            return $this->adaptiveSchema($formatContext);
        }

        return $this->legacySchema($baseSchema, $formatContext);
    }

    /** @param array<string,mixed> $formatContext @return array<string,mixed> */
    private function adaptiveSchema(array $formatContext): array
    {
        $fieldProperties = [];
        $fieldRequired = [];
        foreach ((array) ($formatContext['ai_standard_fields'] ?? []) as $field) {
            if (! is_array($field)) {
                continue;
            }
            $path = trim((string) ($field['path'] ?? ''));
            if ($path === '' || in_array($path, ['planning.title', 'pedagogical_design.purpose'], true)) {
                continue;
            }
            $fieldProperties[$path] = $this->schemaFor($field);
            if ((bool) ($field['required'] ?? true)) {
                $fieldRequired[] = $path;
            }
        }
        ksort($fieldProperties, SORT_STRING);
        sort($fieldRequired, SORT_STRING);

        [$customProperties, $customRequired] = $this->customProperties($formatContext);

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://planeaciones.local/schemas/adaptive-template-generation-v1.dynamic.json',
            'title' => 'AdaptiveTemplateGenerationV1',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['contract_version', 'core', 'fields', 'custom'],
            'properties' => [
                'contract_version' => ['const' => self::ADAPTIVE_CONTRACT_VERSION],
                'core' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => [
                        'title', 'purpose', 'learning_goals', 'assessment_strategy',
                        'adaptation_considerations', 'pda_coverage',
                    ],
                    'properties' => [
                        'title' => ['type' => 'string', 'minLength' => 1],
                        'purpose' => ['type' => 'string', 'minLength' => 1],
                        'learning_goals' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => ['type' => 'string', 'minLength' => 1],
                        ],
                        'assessment_strategy' => ['type' => 'string', 'minLength' => 1],
                        'adaptation_considerations' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'minLength' => 1],
                        ],
                        'pda_coverage' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['pda_code', 'explanation'],
                                'properties' => [
                                    'pda_code' => ['type' => 'string', 'minLength' => 1],
                                    'explanation' => ['type' => 'string', 'minLength' => 1],
                                ],
                            ],
                        ],
                    ],
                ],
                'fields' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => $fieldRequired,
                    'properties' => $fieldProperties,
                ],
                'custom' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => $customRequired,
                    'properties' => $customProperties,
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $baseSchema @param array<string,mixed> $formatContext @return array<string,mixed> */
    private function legacySchema(array $baseSchema, array $formatContext): array
    {
        [$properties, $required] = $this->customProperties($formatContext);
        if ($properties === []) {
            return $baseSchema;
        }

        $baseSchema['properties']['custom'] = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $required,
            'properties' => $properties,
        ];

        $rootRequired = array_values(array_map('strval', is_array($baseSchema['required'] ?? null) ? $baseSchema['required'] : []));
        if (! in_array('custom', $rootRequired, true)) {
            $rootRequired[] = 'custom';
        }
        $baseSchema['required'] = $rootRequired;

        return $baseSchema;
    }

    /** @param array<string,mixed> $formatContext @return array{0:array<string,mixed>,1:list<string>} */
    private function customProperties(array $formatContext): array
    {
        $properties = [];
        $required = [];
        foreach ((array) ($formatContext['custom_fields'] ?? []) as $field) {
            if (! is_array($field) || ($field['source'] ?? null) !== 'ai') {
                continue;
            }
            $key = trim((string) ($field['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $properties[$key] = $this->schemaFor($field);
            if ((bool) ($field['required'] ?? false)) {
                $required[] = $key;
            }
        }
        ksort($properties, SORT_STRING);
        sort($required, SORT_STRING);

        return [$properties, $required];
    }

    /** @param array<string,mixed> $field @return array<string,mixed> */
    private function schemaFor(array $field): array
    {
        $type = (string) ($field['type'] ?? 'long_text');

        if (in_array($type, ['table', 'repeating_block'], true)) {
            $itemFields = is_array($field['item_fields'] ?? null) ? $field['item_fields'] : [];
            if ($itemFields === []) {
                return [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => ['type' => 'object', 'additionalProperties' => true],
                ];
            }

            $properties = [];
            $required = [];
            foreach ($itemFields as $key => $definition) {
                if (! is_array($definition)) {
                    continue;
                }
                $key = trim((string) $key);
                if ($key === '') {
                    continue;
                }
                $properties[$key] = $this->scalarSchema((string) ($definition['type'] ?? 'long_text'));
                if ((bool) ($definition['required'] ?? true)) {
                    $required[] = $key;
                }
            }
            ksort($properties, SORT_STRING);
            sort($required, SORT_STRING);

            return [
                'type' => 'array',
                'minItems' => 1,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => $required,
                    'properties' => $properties,
                ],
            ];
        }

        return $this->scalarSchema($type);
    }

    /** @return array<string,mixed> */
    private function scalarSchema(string $type): array
    {
        return match ($type) {
            'list' => [
                'type' => 'array',
                'minItems' => 1,
                'items' => ['type' => 'string', 'minLength' => 1],
            ],
            'date' => [
                'type' => 'string',
                'format' => 'date',
                'minLength' => 10,
            ],
            default => ['type' => 'string', 'minLength' => 1],
        };
    }
}
