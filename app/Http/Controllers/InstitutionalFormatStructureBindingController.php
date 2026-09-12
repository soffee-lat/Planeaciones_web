<?php

namespace App\Http\Controllers;

use App\Actions\Documents\ConfigureInstitutionalFormatMapping;
use App\Enums\RoleCode;
use App\Exceptions\DocumentFormatException;
use App\Models\InstitutionalFormat;
use App\Models\User;
use App\Services\Documents\InstitutionalFormatFieldCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class InstitutionalFormatStructureBindingController
{
    public function __invoke(
        Request $request,
        InstitutionalFormat $format,
        ConfigureInstitutionalFormatMapping $configure,
        InstitutionalFormatFieldCatalog $catalog,
    ): JsonResponse {
        $user = auth()->user();
        $this->assertOwner($format, $user);

        $version = $format->versions()->reorder()->orderByDesc('number')->firstOrFail();
        if ($version->published_at !== null) {
            throw new DocumentFormatException('FORMAT_MAPPING_STATE_INVALID');
        }

        $data = $request->validate([
            'zone_id' => ['required', 'string', 'max:80'],
            'mode' => ['required', 'in:save,delete'],
            'kind' => ['nullable', 'in:repeat_row,repeat_block'],
            'label' => ['nullable', 'string', 'max:120'],
            'instruction' => ['nullable', 'string', 'max:1000'],
            'fields' => ['nullable', 'array', 'max:50'],
            'fields.*.zone_id' => ['required_with:fields', 'string', 'max:80'],
            'fields.*.mode' => ['required_with:fields', 'in:ai,path,manual'],
            'fields.*.field_path' => ['nullable', 'string', 'max:160'],
            'fields.*.label' => ['nullable', 'string', 'max:120'],
            'fields.*.type' => ['nullable', 'in:text,long_text,date,list'],
            'fields.*.instruction' => ['nullable', 'string', 'max:1000'],
            'fields.*.required' => ['nullable', 'boolean'],
        ]);

        $analysis = data_get($version->validation_report, 'analysis', []);
        $analysis = is_array($analysis) ? $analysis : [];
        $structureZone = $this->structureZone($analysis, trim((string) $data['zone_id']));
        $mapping = is_array($version->mapping) ? $version->mapping : [];
        $mapping['schema_version'] = 3;
        foreach (['anchors', 'placeholders', 'fragments', 'structures', 'custom_fields', 'ignored_zones'] as $key) {
            $mapping[$key] = is_array($mapping[$key] ?? null) ? $mapping[$key] : [];
        }

        $existingId = $this->structureIdForZone($mapping, (string) $structureZone['id']);
        if ($data['mode'] === 'delete') {
            if ($existingId !== null) {
                $fieldPath = (string) ($mapping['structures'][$existingId]['field_path'] ?? '');
                unset($mapping['structures'][$existingId]);
                if (str_starts_with($fieldPath, 'custom.')) {
                    $key = substr($fieldPath, strlen('custom.'));
                    if (! $this->customFieldReferenced($mapping, $key)) {
                        unset($mapping['custom_fields'][$key]);
                    }
                }
            }

            $configured = $configure->execute($version, $mapping, $user, preserveVisualBindings: false);

            return response()->json(['ok' => true, 'mapping' => $configured->mapping]);
        }

        $kind = (string) ($data['kind'] ?? '');
        if (($kind === 'repeat_row' && ($structureZone['kind'] ?? null) !== 'row')
            || ($kind === 'repeat_block' && ! in_array(($structureZone['kind'] ?? null), ['row', 'table'], true))) {
            throw new DocumentFormatException('FORMAT_MAPPING_STRUCTURE_ZONE_INVALID');
        }

        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            throw new DocumentFormatException('FORMAT_MAPPING_STRUCTURE_LABEL_REQUIRED');
        }
        $instruction = trim((string) ($data['instruction'] ?? ''));
        $childIds = array_fill_keys(array_map('strval', is_array($structureZone['child_zone_ids'] ?? null) ? $structureZone['child_zone_ids'] : []), true);
        if ($childIds === []) {
            throw new DocumentFormatException('FORMAT_MAPPING_STRUCTURE_CHILDREN_REQUIRED');
        }

        $existingPath = $existingId !== null ? (string) ($mapping['structures'][$existingId]['field_path'] ?? '') : '';
        $structureKey = str_starts_with($existingPath, 'custom.')
            ? substr($existingPath, strlen('custom.'))
            : $this->uniqueKey($mapping['custom_fields'], $label);

        $itemFields = [];
        $bindings = [];
        foreach ((array) ($data['fields'] ?? []) as $field) {
            if (! is_array($field)) {
                continue;
            }
            $zoneId = trim((string) ($field['zone_id'] ?? ''));
            if (! isset($childIds[$zoneId])) {
                throw new DocumentFormatException('FORMAT_MAPPING_STRUCTURE_BINDING_INVALID');
            }
            $mode = (string) ($field['mode'] ?? 'manual');
            if ($mode === 'manual') {
                continue;
            }
            if ($mode === 'path') {
                $path = trim((string) ($field['field_path'] ?? ''));
                if ($path === '' || ! array_key_exists($path, $catalog->options())) {
                    throw new DocumentFormatException('FORMAT_MAPPING_STRUCTURE_BINDING_INVALID');
                }
                $bindings[$zoneId] = ['source' => 'path', 'field_path' => $path];
                continue;
            }

            $fieldLabel = trim((string) ($field['label'] ?? ''));
            if ($fieldLabel === '') {
                throw new DocumentFormatException('FORMAT_MAPPING_STRUCTURE_FIELDS_INVALID');
            }
            $itemKey = $this->uniqueKey($itemFields, $fieldLabel);
            $itemFields[$itemKey] = [
                'label' => $fieldLabel,
                'type' => (string) ($field['type'] ?? 'long_text'),
                'instruction' => trim((string) ($field['instruction'] ?? '')),
                'required' => (bool) ($field['required'] ?? true),
            ];
            $bindings[$zoneId] = ['source' => 'item', 'item_key' => $itemKey];
        }

        if ($bindings === [] || $itemFields === []) {
            throw new DocumentFormatException('FORMAT_MAPPING_STRUCTURE_BINDING_INVALID');
        }

        foreach (array_keys($bindings) as $zoneId) {
            $this->removeTopLevelBindings($mapping, $analysis, $zoneId);
        }

        $mapping['custom_fields'][$structureKey] = [
            'label' => $label,
            'type' => 'repeating_block',
            'instruction' => $instruction,
            'item_fields' => $itemFields,
        ];
        $structureId = $existingId ?? 's_' . substr(hash('sha256', (string) $structureZone['id'] . '|' . $structureKey), 0, 20);
        $mapping['structures'][$structureId] = [
            'zone_id' => (string) $structureZone['id'],
            'kind' => $kind,
            'field_path' => 'custom.' . $structureKey,
            'bindings' => $bindings,
        ];

        $configured = $configure->execute($version, $mapping, $user, preserveVisualBindings: false);

        return response()->json([
            'ok' => true,
            'structure_id' => $structureId,
            'mapping' => $configured->mapping,
        ]);
    }

    /** @param array<string,mixed> $analysis @return array<string,mixed> */
    private function structureZone(array $analysis, string $zoneId): array
    {
        foreach ((array) ($analysis['structural_zones'] ?? []) as $zone) {
            if (is_array($zone) && (string) ($zone['id'] ?? '') === $zoneId) {
                return $zone;
            }
        }

        throw new DocumentFormatException('FORMAT_MAPPING_STRUCTURE_ZONE_INVALID');
    }

    /** @param array<string,mixed> $mapping */
    private function structureIdForZone(array $mapping, string $zoneId): ?string
    {
        foreach ((array) ($mapping['structures'] ?? []) as $id => $structure) {
            if (is_array($structure) && (string) ($structure['zone_id'] ?? '') === $zoneId) {
                return (string) $id;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $mapping */
    private function customFieldReferenced(array $mapping, string $key): bool
    {
        $path = 'custom.' . $key;
        foreach (['anchors', 'placeholders'] as $group) {
            if (in_array($path, array_map('strval', (array) ($mapping[$group] ?? [])), true)) {
                return true;
            }
        }
        foreach ((array) ($mapping['fragments'] ?? []) as $fragment) {
            if (is_array($fragment) && (string) ($fragment['field_path'] ?? '') === $path) {
                return true;
            }
        }
        foreach ((array) ($mapping['structures'] ?? []) as $structure) {
            if (is_array($structure) && (string) ($structure['field_path'] ?? '') === $path) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $mapping @param array<string,mixed> $analysis */
    private function removeTopLevelBindings(array &$mapping, array $analysis, string $zoneId): void
    {
        unset($mapping['anchors'][$zoneId]);
        foreach ((array) ($analysis['anchors'] ?? []) as $anchor) {
            if (! is_array($anchor)) {
                continue;
            }
            $id = (string) ($anchor['id'] ?? '');
            $target = (string) ($anchor['target_id'] ?? '');
            if ($id === $zoneId || $target === $zoneId) {
                unset($mapping['anchors'][$id]);
            }
        }
        foreach ((array) ($mapping['fragments'] ?? []) as $id => $fragment) {
            if (is_array($fragment) && (string) ($fragment['zone_id'] ?? '') === $zoneId) {
                unset($mapping['fragments'][$id]);
            }
        }
        $mapping['ignored_zones'] = array_values(array_filter(
            (array) ($mapping['ignored_zones'] ?? []),
            static fn ($id): bool => (string) $id !== $zoneId,
        ));
    }

    /** @param array<string,mixed> $existing */
    private function uniqueKey(array $existing, string $label): string
    {
        $base = Str::of($label)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
        if ($base === '' || ! preg_match('/^[a-z]/', $base)) {
            $base = 'campo_' . substr(hash('sha256', $label), 0, 8);
        }
        if (strlen($base) < 2) {
            $base .= '_campo';
        }
        $base = substr($base, 0, 50);
        $key = $base;
        $suffix = 2;
        while (array_key_exists($key, $existing)) {
            $key = substr($base, 0, 55) . '_' . $suffix++;
        }

        return $key;
    }

    private function assertOwner(InstitutionalFormat $format, mixed $user): void
    {
        if (! $user instanceof User
            || $user->status !== 'active'
            || ! $user->hasVerifiedEmail()
            || ! $user->hasRole(RoleCode::Customer)
            || (int) $format->owner_id !== (int) $user->id) {
            throw new AccessDeniedHttpException();
        }
    }
}
