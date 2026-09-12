<?php

namespace App\Services\AI;

final class FormatAwareGenerationSchema
{
    /**
     * Extiende el contrato base únicamente para la ejecución actual. El DOCX
     * define qué campos propios necesita; no existe un catálogo universal de
     * formatos escolares.
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

            $properties[$key] = $this->schemaFor((string) ($field['type'] ?? 'long_text'));

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

    /** @return array<string,mixed> */
    private function schemaFor(string $type): array
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
            'table', 'repeating_block' => [
                'type' => 'array',
                'minItems' => 1,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                ],
            ],
            default => ['type' => 'string', 'minLength' => 1],
        };
    }
}
