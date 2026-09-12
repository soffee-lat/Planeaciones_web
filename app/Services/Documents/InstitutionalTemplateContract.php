<?php

namespace App\Services\Documents;

use App\Models\FormatVersion;

/**
 * Construye el contrato sidecar del formato. El contrato describe únicamente
 * las zonas del DOCX y sus fuentes; nunca intenta convertir el documento en
 * un formato universal ni presupone que dos escuelas comparten estructura.
 */
final class InstitutionalTemplateContract
{
    /**
     * @param array<string,mixed> $mapping Mapping ya validado.
     * @return array<string,mixed>
     */
    public function build(FormatVersion $version, array $mapping): array
    {
        $analysis = data_get($version->validation_report, 'analysis', []);
        $analysis = is_array($analysis) ? $analysis : [];
        $anchorMap = is_array($mapping['anchors'] ?? null) ? $mapping['anchors'] : [];
        $placeholderMap = is_array($mapping['placeholders'] ?? null) ? $mapping['placeholders'] : [];
        $fragments = is_array($mapping['fragments'] ?? null) ? $mapping['fragments'] : [];
        $custom = is_array($mapping['custom_fields'] ?? null) ? $mapping['custom_fields'] : [];
        $fields = [];

        foreach ((array) ($analysis['anchors'] ?? []) as $position => $anchor) {
            if (! is_array($anchor)) {
                continue;
            }
            $id = trim((string) ($anchor['id'] ?? ''));
            $path = trim((string) ($anchorMap[$id] ?? ''));
            if ($id === '' || $path === '') {
                continue;
            }

            $fields[] = $this->field(
                bindingKind: 'anchor',
                bindingId: $id,
                path: $path,
                label: trim((string) ($anchor['label'] ?? $id)),
                type: $this->typeFor($path, $custom),
                instruction: $this->instructionFor($path, $custom),
                example: $this->cleanExample($anchor['current_value_excerpt'] ?? null),
                position: (int) $position,
            );
        }

        $placeholderPosition = count($fields);
        foreach ((array) ($analysis['placeholders'] ?? []) as $token) {
            $token = trim((string) $token);
            $path = trim((string) ($placeholderMap[$token] ?? ''));
            if ($token === '' || $path === '') {
                continue;
            }
            $fields[] = $this->field(
                bindingKind: 'placeholder',
                bindingId: $token,
                path: $path,
                label: $this->labelFor($path, $custom, $token),
                type: $this->typeFor($path, $custom),
                instruction: $this->instructionFor($path, $custom),
                example: null,
                position: $placeholderPosition++,
            );
        }

        foreach ($fragments as $fragmentId => $fragment) {
            if (! is_array($fragment)) {
                continue;
            }
            $path = trim((string) ($fragment['field_path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $fields[] = $this->field(
                bindingKind: 'fragment',
                bindingId: (string) $fragmentId,
                path: $path,
                label: trim((string) ($fragment['label_hint'] ?? $this->labelFor($path, $custom, (string) $fragmentId))),
                type: $this->typeFor($path, $custom),
                instruction: $this->instructionFor($path, $custom),
                example: $this->cleanExample($fragment['source_text'] ?? null),
                position: count($fields),
            );
        }

        $sourceFile = $version->relationLoaded('sourceFile')
            ? $version->getRelation('sourceFile')
            : null;

        return [
            'schema_version' => 1,
            'authority' => 'user_docx',
            'source_sha256' => $sourceFile?->sha256,
            'source_content_mode' => $analysis['source_content_mode'] ?? null,
            'fields' => $fields,
            'ignored_zones' => array_values(array_map('strval', is_array($mapping['ignored_zones'] ?? null) ? $mapping['ignored_zones'] : [])),
            'rules' => [
                'El DOCX del usuario es la autoridad visual y estructural; no lo rediseñes ni lo normalices a otro formato.',
                'Genera únicamente los campos cuya fuente sea ai. Los datos de sistema y currículo se resuelven por el sistema.',
                'Los ejemplos históricos sirven para entender intención, extensión y estilo; no deben copiarse literalmente.',
                'No supongas que las etiquetas, repeticiones, tablas o secciones de este formato existen en otros formatos.',
                'Los campos manuales, firmas, sellos y zonas ignoradas no deben inventarse.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function field(
        string $bindingKind,
        string $bindingId,
        string $path,
        string $label,
        string $type,
        string $instruction,
        ?string $example,
        int $position,
    ): array {
        $source = $this->sourceFor($path);

        return [
            'binding' => ['kind' => $bindingKind, 'id' => $bindingId],
            'position' => $position,
            'path' => $path,
            'label' => $label !== '' ? $label : $path,
            'type' => $type,
            'source' => $source,
            'required' => $source === 'ai',
            'instruction' => $instruction,
            'example' => $source === 'ai' ? $example : null,
        ];
    }

    /** @param array<string,mixed> $custom */
    private function typeFor(string $path, array $custom): string
    {
        if (str_starts_with($path, 'custom.')) {
            $key = substr($path, strlen('custom.'));
            return (string) ($custom[$key]['type'] ?? 'long_text');
        }
        if (in_array($path, [
            'curricular_alignment.fields', 'curricular_alignment.contents', 'curricular_alignment.pdas',
            'curricular_alignment.articulating_axes', 'resources.physical_materials', 'resources.digital_resources',
        ], true)) {
            return 'list';
        }
        if (in_array($path, ['planning.starts_on', 'planning.ends_on'], true)) {
            return 'date';
        }

        return 'long_text';
    }

    /** @param array<string,mixed> $custom */
    private function instructionFor(string $path, array $custom): string
    {
        if (str_starts_with($path, 'custom.')) {
            $key = substr($path, strlen('custom.'));
            return trim((string) ($custom[$key]['instruction'] ?? ''));
        }

        return match (true) {
            str_starts_with($path, 'curricular_alignment.') => 'Usa exclusivamente la selección curricular confirmada para esta planeación.',
            str_starts_with($path, 'context.') => 'Este dato lo proporciona el perfil del grupo; no lo inventes.',
            in_array($path, ['planning.starts_on', 'planning.ends_on'], true) => 'Este dato lo proporciona la solicitud; no lo inventes.',
            default => 'Completa este apartado de forma coherente con el currículo, el grupo y el resto de la planeación.',
        };
    }

    private function sourceFor(string $path): string
    {
        if (str_starts_with($path, 'curricular_alignment.')) {
            return 'curriculum';
        }
        if (str_starts_with($path, 'context.') || in_array($path, ['planning.starts_on', 'planning.ends_on'], true)) {
            return 'system';
        }
        if (str_starts_with($path, 'custom.')) {
            return 'ai';
        }

        return 'ai';
    }

    /** @param array<string,mixed> $custom */
    private function labelFor(string $path, array $custom, string $fallback): string
    {
        if (str_starts_with($path, 'custom.')) {
            $key = substr($path, strlen('custom.'));
            $label = trim((string) ($custom[$key]['label'] ?? ''));
            if ($label !== '') {
                return $label;
            }
        }

        return $fallback;
    }

    private function cleanExample(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, 240);
    }
}
