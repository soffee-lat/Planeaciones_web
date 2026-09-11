<?php

namespace App\Services\AI;

final class FormatAwareGenerationSchema
{
    /**
     * Extiende el contrato base solo para la ejecución actual. El esquema base
     * permanece estable; los campos custom dependen del formato institucional
     * resuelto para esa planeación.
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

            $type = (string) ($field['type'] ?? 'long_text');
            $properties[$key] = $type === 'list'
                ? [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => ['type' => 'string', 'minLength' => 1],
                ]
                : ['type' => 'string', 'minLength' => 1];

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
}
