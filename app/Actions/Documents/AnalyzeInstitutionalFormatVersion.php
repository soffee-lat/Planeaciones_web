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
                'custom_fields' => [],
                'ignored_zones' => [],
            ];
            $existing = is_array($locked->mapping) ? $locked->mapping : [];

            if (($existing['schema_version'] ?? null) === 2) {
                $suggested['custom_fields'] = is_array($existing['custom_fields'] ?? null) ? $existing['custom_fields'] : [];
                $suggested['ignored_zones'] = is_array($existing['ignored_zones'] ?? null) ? $existing['ignored_zones'] : [];
                $suggested['anchors'] = array_replace(
                    is_array($suggested['anchors'] ?? null) ? $suggested['anchors'] : [],
                    is_array($existing['anchors'] ?? null) ? $existing['anchors'] : [],
                );
                $suggested['placeholders'] = array_replace(
                    is_array($suggested['placeholders'] ?? null) ? $suggested['placeholders'] : [],
                    is_array($existing['placeholders'] ?? null) ? $existing['placeholders'] : [],
                );
                foreach ($suggested['ignored_zones'] as $ignored) {
                    unset($suggested['anchors'][(string) $ignored]);
                }
            }

            $locked->forceFill([
                'validation_report' => ['status' => 'analysis_complete', 'analysis' => $analysis],
            ])->save();
            $normalized = $this->mapping->validate($locked->fresh(), $suggested);
            $hasMapping = ($normalized['anchors'] ?? []) !== [] || ($normalized['placeholders'] ?? []) !== [];

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
