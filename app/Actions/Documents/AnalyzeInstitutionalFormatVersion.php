<?php

namespace App\Actions\Documents;

use App\Enums\InstitutionalFormatKind;
use App\Enums\InstitutionalFormatStatus;
use App\Enums\RoleCode;
use App\Exceptions\DocumentFormatException;
use App\Models\FormatVersion;
use App\Models\User;
use App\Services\Documents\InstitutionalFormatMapping;
use App\Services\Documents\InstitutionalFormatSourceInspector;
use Illuminate\Support\Facades\DB;

final class AnalyzeInstitutionalFormatVersion
{
    public function __construct(
        private InstitutionalFormatSourceInspector $inspector,
        private InstitutionalFormatMapping $mapping,
    ) {}

    public function execute(FormatVersion $version, User $actor): FormatVersion
    {
        $version->loadMissing(['format', 'sourceFile']);
        $this->assertOwner($version, $actor);
        if ($version->published_at !== null || $version->format?->kind !== InstitutionalFormatKind::Institutional) {
            throw new DocumentFormatException('FORMAT_ANALYSIS_STATE_INVALID');
        }
        if (! $version->sourceFile || (int) $version->sourceFile->owner_id !== (int) $version->format->owner_id) {
            throw new DocumentFormatException('FORMAT_VERSION_SOURCE_NOT_READY');
        }

        try {
            $analysis = $this->inspector->inspect($version->sourceFile);
        } catch (DocumentFormatException $e) {
            DB::transaction(function () use ($version, $e): void {
                $locked = FormatVersion::query()->with('format')->whereKey($version->id)->lockForUpdate()->firstOrFail();
                $locked->forceFill(['validation_report' => ['status' => 'unsupported', 'analysis' => ['error_code' => $e->getMessage()]]])->save();
                $locked->format->forceFill(['status' => InstitutionalFormatStatus::Unsupported->value])->save();
            }, attempts: 3);
            throw $e;
        }

        return DB::transaction(function () use ($version, $analysis): FormatVersion {
            $locked = FormatVersion::query()->with(['format', 'sourceFile'])->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($locked->published_at !== null || ! $locked->sourceFile || $locked->sourceFile->sha256 !== $analysis['source_sha256']) {
                throw new DocumentFormatException('FORMAT_ANALYSIS_STALE');
            }

            $suggested = is_array($analysis['suggested_mapping'] ?? null) ? $analysis['suggested_mapping'] : [
                'schema_version' => 2,
                'anchors' => [],
                'placeholders' => [],
                'fragments' => [],
                'custom_fields' => [],
                'ignored_zones' => [],
            ];
            $suggested['fragments'] = is_array($suggested['fragments'] ?? null) ? $suggested['fragments'] : [];
            $existing = is_array($locked->mapping) ? $locked->mapping : [];

            if (($existing['schema_version'] ?? null) === 2) {
                $suggested['custom_fields'] = is_array($existing['custom_fields'] ?? null) ? $existing['custom_fields'] : [];
                $suggested['ignored_zones'] = is_array($existing['ignored_zones'] ?? null) ? $existing['ignored_zones'] : [];
                $suggested['fragments'] = is_array($existing['fragments'] ?? null) ? $existing['fragments'] : $suggested['fragments'];
                $suggested['anchors'] = array_replace(
                    is_array($suggested['anchors'] ?? null) ? $suggested['anchors'] : [],
                    is_array($existing['anchors'] ?? null) ? $existing['anchors'] : [],
                );
                $suggested['placeholders'] = array_replace(
                    is_array($suggested['placeholders'] ?? null) ? $suggested['placeholders'] : [],
                    is_array($existing['placeholders'] ?? null) ? $existing['placeholders'] : [],
                );
            }

            // A line with several label:value pairs must never be treated as one
            // replaceable field. It needs precise fragment selection in the visual
            // designer (e.g. GRADO: 3°  GRUPO: A).
            $zoneText = [];
            foreach ((array) ($analysis['document_zones'] ?? []) as $zone) {
                if (is_array($zone) && is_string($zone['id'] ?? null)) {
                    $zoneText[$zone['id']] = (string) ($zone['text_excerpt'] ?? '');
                }
            }
            foreach ((array) ($analysis['anchors'] ?? []) as $anchor) {
                if (! is_array($anchor)) {
                    continue;
                }
                $id = (string) ($anchor['id'] ?? '');
                $target = (string) ($anchor['target_id'] ?? $id);
                if (substr_count($zoneText[$target] ?? '', ':') > 1) {
                    unset($suggested['anchors'][$id]);
                }
            }

            // Precise manual fragments are authoritative. Re-analysis can suggest
            // other zones, but it cannot restore a whole-zone binding on top of a
            // value the teacher already selected explicitly.
            $fragmentZones = [];
            foreach ($suggested['fragments'] as $fragment) {
                if (is_array($fragment) && is_string($fragment['zone_id'] ?? null)) {
                    $fragmentZones[$fragment['zone_id']] = true;
                }
            }
            foreach ((array) ($analysis['anchors'] ?? []) as $anchor) {
                if (! is_array($anchor)) {
                    continue;
                }
                $id = (string) ($anchor['id'] ?? '');
                $target = (string) ($anchor['target_id'] ?? $id);
                if (isset($fragmentZones[$id]) || isset($fragmentZones[$target])) {
                    unset($suggested['anchors'][$id]);
                }
            }

            foreach ($suggested['ignored_zones'] as $ignored) {
                unset($suggested['anchors'][(string) $ignored]);
                foreach ($suggested['fragments'] as $id => $fragment) {
                    if (is_array($fragment) && (string) ($fragment['zone_id'] ?? '') === (string) $ignored) {
                        unset($suggested['fragments'][$id]);
                    }
                }
            }

            $locked->forceFill([
                'validation_report' => ['status' => 'analysis_complete', 'analysis' => $analysis],
            ])->save();

            $hasRequestedMapping = (is_array($suggested['anchors'] ?? null) && $suggested['anchors'] !== [])
                || (is_array($suggested['placeholders'] ?? null) && $suggested['placeholders'] !== [])
                || (is_array($suggested['fragments'] ?? null) && $suggested['fragments'] !== []);

            $normalized = $hasRequestedMapping
                ? $this->mapping->validate($locked->fresh(), $suggested)
                : [
                    'schema_version' => 2,
                    'anchors' => [],
                    'placeholders' => [],
                    'fragments' => [],
                    'custom_fields' => is_array($suggested['custom_fields'] ?? null) ? $suggested['custom_fields'] : [],
                    'ignored_zones' => is_array($suggested['ignored_zones'] ?? null) ? array_values($suggested['ignored_zones']) : [],
                ];

            $hasMapping = ($normalized['anchors'] ?? []) !== []
                || ($normalized['placeholders'] ?? []) !== []
                || ($normalized['fragments'] ?? []) !== [];

            $locked->forceFill([
                'mapping' => $normalized,
                'schema_version' => 2,
                'renderer' => 'institutional-v1',
                'validation_report' => ['status' => $hasMapping ? 'mapping_ready' : 'analysis_complete', 'analysis' => $analysis],
            ])->save();
            $locked->format->forceFill(['status' => InstitutionalFormatStatus::Configuring->value])->save();

            return $locked->fresh(['format', 'sourceFile']);
        }, attempts: 3);
    }

    private function assertOwner(FormatVersion $version, User $actor): void
    {
        if ($actor->status !== 'active' || ! $actor->hasVerifiedEmail() || ! $actor->hasRole(RoleCode::Customer)
            || (int) $version->format?->owner_id !== (int) $actor->id) {
            throw new DocumentFormatException('FORMAT_OWNER_REQUIRED');
        }
    }
}
