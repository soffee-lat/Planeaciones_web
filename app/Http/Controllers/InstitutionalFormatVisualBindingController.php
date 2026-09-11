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

        $version = $format->versions()->orderByDesc('number')->firstOrFail();
        if ($version->published_at !== null) {
            throw new DocumentFormatException('FORMAT_MAPPING_STATE_INVALID');
        }

        $data = $request->validate([
            'zone_id' => ['required', 'string', 'max:80'],
            'mode' => ['required', 'in:bind,custom,ignore,unbind'],
            'field_path' => ['nullable', 'string', 'max:160'],
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
        $mapping['anchors'] = is_array($mapping['anchors'] ?? null) ? $mapping['anchors'] : [];
        $mapping['placeholders'] = is_array($mapping['placeholders'] ?? null) ? $mapping['placeholders'] : [];
        $mapping['custom_fields'] = is_array($mapping['custom_fields'] ?? null) ? $mapping['custom_fields'] : [];
        $mapping['ignored_zones'] = is_array($mapping['ignored_zones'] ?? null) ? $mapping['ignored_zones'] : [];

        $mapping['ignored_zones'] = array_values(array_filter(
            $mapping['ignored_zones'],
            static fn ($id): bool => (string) $id !== $zoneId,
        ));

        if ($data['mode'] === 'bind') {
            $fieldPath = trim((string) ($data['field_path'] ?? ''));
            $allowed = $catalog->options();
            foreach ($mapping['custom_fields'] as $key => $definition) {
                if (is_array($definition)) {
                    $allowed['custom.' . $key] = (string) ($definition['label'] ?? $key);
                }
            }
            if ($fieldPath === '' || ! array_key_exists($fieldPath, $allowed)) {
                throw new DocumentFormatException('FORMAT_MAPPING_PATH_NOT_ALLOWED:' . $fieldPath);
            }
            $mapping['anchors'][$zoneId] = $fieldPath;
        } elseif ($data['mode'] === 'custom') {
            $label = trim((string) ($data['custom_label'] ?? ''));
            if ($label === '') {
                throw new DocumentFormatException('FORMAT_MAPPING_CUSTOM_FIELDS_INVALID');
            }
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
            $mapping['anchors'][$zoneId] = 'custom.' . $key;
        } elseif ($data['mode'] === 'ignore') {
            unset($mapping['anchors'][$zoneId]);
            $mapping['ignored_zones'][] = $zoneId;
        } elseif ($data['mode'] === 'unbind') {
            unset($mapping['anchors'][$zoneId]);
        }

        $configured = $configure->execute($version, $mapping, $user);
        $normalized = is_array($configured->mapping) ? $configured->mapping : [];
        $path = $normalized['anchors'][$zoneId] ?? null;

        $label = null;
        if (is_string($path)) {
            if (str_starts_with($path, 'custom.')) {
                $key = substr($path, strlen('custom.'));
                $label = (string) data_get($normalized, 'custom_fields.' . $key . '.label', $key);
            } else {
                $label = $catalog->labelFor($path);
            }
        }

        return response()->json([
            'ok' => true,
            'zone_id' => $zoneId,
            'field_path' => $path,
            'field_label' => $label,
            'ignored' => in_array($zoneId, (array) ($normalized['ignored_zones'] ?? []), true),
            'mapping' => $normalized,
        ]);
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
