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

final class InstitutionalFormatVisualBindingController
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
            'binding_id' => ['nullable', 'string', 'max:80'],
            'mode' => ['required', 'in:bind,custom,ignore,unbind'],
            'field_path' => ['nullable', 'string', 'max:160'],
            'fragment_start' => ['nullable', 'integer', 'min:0', 'max:50000'],
            'fragment_end' => ['nullable', 'integer', 'min:1', 'max:50000'],
            'fragment_text' => ['nullable', 'string', 'max:1000'],
            'label_hint' => ['nullable', 'string', 'max:160'],
            'custom_label' => ['nullable', 'string', 'max:120'],
            'custom_type' => ['nullable', 'in:text,long_text,date,list,table,repeating_block'],
            'custom_instruction' => ['nullable', 'string', 'max:1000'],
        ]);

        $analysis = data_get($version->validation_report, 'analysis', []);
        $zoneIds = [];
        foreach ((array) ($analysis['document_zones'] ?? []) as $zone) {
            if (is_array($zone) && is_string($zone['id'] ?? null)) {
                $zoneIds[$zone['id']] = true;
            }
        }
        foreach ((array) ($analysis['anchors'] ?? []) as $anchor) {
            if (is_array($anchor) && is_string($anchor['id'] ?? null)) {
                $zoneIds[$anchor['id']] = true;
            }
        }

        $zoneId = trim($data['zone_id']);
        if (! isset($zoneIds[$zoneId])) {
            throw new DocumentFormatException('FORMAT_MAPPING_ANCHOR_MISMATCH');
        }

        $mapping = is_array($version->mapping) ? $version->mapping : [];
        $mapping['schema_version'] = 2;
        foreach (['anchors', 'placeholders', 'fragments', 'custom_fields', 'ignored_zones'] as $key) {
            $mapping[$key] = is_array($mapping[$key] ?? null) ? $mapping[$key] : [];
        }

        $bindingId = trim((string) ($data['binding_id'] ?? ''));
        $hasFragment = isset($data['fragment_start'], $data['fragment_end'])
            && trim((string) ($data['fragment_text'] ?? '')) !== '';

        if ($hasFragment && (int) $data['fragment_end'] <= (int) $data['fragment_start']) {
            throw new DocumentFormatException('FORMAT_MAPPING_FRAGMENT_INVALID');
        }

        $mapping['ignored_zones'] = array_values(array_filter(
            $mapping['ignored_zones'],
            static fn ($id): bool => (string) $id !== $zoneId,
        ));

        if ($data['mode'] === 'bind') {
            $fieldPath = trim((string) ($data['field_path'] ?? ''));
            $this->assertAllowedField($fieldPath, $mapping, $catalog);
            $this->bind($mapping, $analysis, $zoneId, $bindingId, $hasFragment, $data, $fieldPath);
        } elseif ($data['mode'] === 'custom') {
            $label = trim((string) ($data['custom_label'] ?? ''));
            if ($label === '') {
                throw new DocumentFormatException('FORMAT_MAPPING_CUSTOM_FIELDS_INVALID');
            }
            $path = $this->createCustomField($mapping, $label, $data);
            $this->bind($mapping, $analysis, $zoneId, $bindingId, $hasFragment, $data, $path);
        } elseif ($data['mode'] === 'ignore') {
            $this->removeWholeZoneBindings($mapping, $analysis, $zoneId);
            $mapping['ignored_zones'][] = $zoneId;
        } elseif ($data['mode'] === 'unbind') {
            if ($bindingId !== '' && isset($mapping['fragments'][$bindingId])) {
                unset($mapping['fragments'][$bindingId]);
            } else {
                unset($mapping['anchors'][$zoneId]);
                foreach ((array) ($analysis['anchors'] ?? []) as $anchor) {
                    if (is_array($anchor) && (string) ($anchor['target_id'] ?? '') === $zoneId) {
                        unset($mapping['anchors'][(string) ($anchor['id'] ?? '')]);
                    }
                }
            }
        }

        $mapping['ignored_zones'] = array_values(array_unique(array_map('strval', $mapping['ignored_zones'])));
        $configured = app(ConfigureInstitutionalFormatMapping::class)->execute($version, $mapping, $user, preserveVisualBindings: false);
        $normalized = is_array($configured->mapping) ? $configured->mapping : [];

        return response()->json([
            'ok' => true,
            'zone_id' => $zoneId,
            'mapping' => $normalized,
        ]);
    }

    /** @param array<string,mixed> $mapping */
    private function assertAllowedField(string $fieldPath, array $mapping, InstitutionalFormatFieldCatalog $catalog): void
    {
        $allowed = $catalog->options();
        foreach ((array) ($mapping['custom_fields'] ?? []) as $key => $definition) {
            if (is_array($definition)) {
                $allowed['custom.' . $key] = (string) ($definition['label'] ?? $key);
            }
        }
        if ($fieldPath === '' || ! array_key_exists($fieldPath, $allowed)) {
            throw new DocumentFormatException('FORMAT_MAPPING_PATH_NOT_ALLOWED:' . $fieldPath);
        }
    }

    /** @param array<string,mixed> $mapping @param array<string,mixed> $data @return string */
    private function createCustomField(array &$mapping, string $label, array $data): string
    {
        $base = Str::of($label)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
        if ($base === '' || ! preg_match('/^[a-z]/', $base)) {
            $base = 'campo_' . substr(hash('sha256', $label), 0, 8);
        }
        $base = substr($base, 0, 50);
        $key = $base;
        $suffix = 2;
        while (isset($mapping['custom_fields'][$key])) {
            $key = substr($base, 0, 55) . '_' . $suffix++;
        }
        $mapping['custom_fields'][$key] = [
            'label' => $label,
            'type' => (string) ($data['custom_type'] ?? 'long_text'),
            'instruction' => trim((string) ($data['custom_instruction'] ?? '')),
        ];

        return 'custom.' . $key;
    }

    /** @param array<string,mixed> $mapping @param array<string,mixed> $analysis @param array<string,mixed> $data */
    private function bind(array &$mapping, array $analysis, string $zoneId, string $bindingId, bool $hasFragment, array $data, string $fieldPath): void
    {
        if (! $hasFragment) {
            $this->removeFragmentsForZone($mapping, $zoneId);
            $mapping['anchors'][$zoneId] = $fieldPath;
            return;
        }

        $this->removeWholeZoneBindings($mapping, $analysis, $zoneId, removeFragments: false);
        $start = (int) $data['fragment_start'];
        $end = (int) $data['fragment_end'];
        $sourceText = trim((string) $data['fragment_text']);
        $id = $bindingId;
        if ($id === '' || ! isset($mapping['fragments'][$id])) {
            $id = 'f_' . substr(hash('sha256', $zoneId . '|' . $start . '|' . $end . '|' . $sourceText), 0, 20);
        }

        $mapping['fragments'][$id] = [
            'zone_id' => $zoneId,
            'start' => $start,
            'end' => $end,
            'source_text' => $sourceText,
            'label_hint' => trim((string) ($data['label_hint'] ?? '')),
            'field_path' => $fieldPath,
        ];
    }

    /** @param array<string,mixed> $mapping @param array<string,mixed> $analysis */
    private function removeWholeZoneBindings(array &$mapping, array $analysis, string $zoneId, bool $removeFragments = true): void
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
        if ($removeFragments) {
            $this->removeFragmentsForZone($mapping, $zoneId);
        }
    }

    /** @param array<string,mixed> $mapping */
    private function removeFragmentsForZone(array &$mapping, string $zoneId): void
    {
        foreach ((array) ($mapping['fragments'] ?? []) as $id => $fragment) {
            if (is_array($fragment) && (string) ($fragment['zone_id'] ?? '') === $zoneId) {
                unset($mapping['fragments'][$id]);
            }
        }
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
