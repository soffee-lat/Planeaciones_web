<?php

namespace App\Services\AI;

final class FormatAwareGenerationSchema
{
    /**
     * Extiende el contrato base únicamente para la ejecución actual. El DOCX
     * define qué campos propios necesita; no existe un catálogo universal de
     * formatos escolares.
     *
     * Los tipos estructurados (`table` y `repeating_block`) pueden declarar
     * `item_fields`; cuando existen, el schema deja de aceptar objetos libres y
     * exige exactamente la estructura confirmada por el usuario.
     *
     * @param array<string,mixed> $baseSchema
     * @param array<string,mixed> $formatContext
     * @return array<string,mixed>
     */
    public function extend(array $baseSchema, array $formatContext): array
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

        if ($properties === []) {
            return $baseSchema;
        }

        ksort($properties, SORT_STRING);
        sort($required, SORT_STRING);

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
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                    ],
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
