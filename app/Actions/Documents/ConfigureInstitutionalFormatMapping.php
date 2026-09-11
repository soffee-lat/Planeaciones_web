<?php

namespace App\Actions\Documents;

use App\Enums\InstitutionalFormatKind;
use App\Enums\InstitutionalFormatStatus;
use App\Enums\RoleCode;
use App\Exceptions\DocumentFormatException;
use App\Models\FormatVersion;
use App\Models\User;
use App\Services\Documents\InstitutionalFormatMapping;
use Illuminate\Support\Facades\DB;

final class ConfigureInstitutionalFormatMapping
{
    public function __construct(private InstitutionalFormatMapping $mapping) {}

    /** @param array<string,mixed> $mapping */
    public function execute(FormatVersion $version, array $mapping, User $actor): FormatVersion
    {
        $version->loadMissing('format');
        $this->assertOwner($version, $actor);

        return DB::transaction(function () use ($version, $mapping): FormatVersion {
            $locked = FormatVersion::query()->with(['format', 'sourceFile'])->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($locked->published_at !== null || $locked->format?->kind !== InstitutionalFormatKind::Institutional) {
                throw new DocumentFormatException('FORMAT_MAPPING_STATE_INVALID');
            }

            $incoming = $mapping;
            $existing = is_array($locked->mapping) ? $locked->mapping : [];
            $analysis = is_array(data_get($locked->validation_report, 'analysis')) ? data_get($locked->validation_report, 'analysis') : [];

            if (! array_key_exists('custom_fields', $incoming)) {
                $incoming['custom_fields'] = is_array($existing['custom_fields'] ?? null) ? $existing['custom_fields'] : [];
            }
            if (! array_key_exists('ignored_zones', $incoming)) {
                $incoming['ignored_zones'] = is_array($existing['ignored_zones'] ?? null) ? $existing['ignored_zones'] : [];
            }

            $incomingAnchors = is_array($incoming['anchors'] ?? null) ? $incoming['anchors'] : [];
            $existingAnchors = is_array($existing['anchors'] ?? null) ? $existing['anchors'] : [];
            $automaticIds = [];
            foreach ((array) ($analysis['anchors'] ?? []) as $anchor) {
                if (is_array($anchor) && is_string($anchor['id'] ?? null)) {
                    $automaticIds[$anchor['id']] = true;
                }
            }
            foreach ($existingAnchors as $id => $path) {
                $id = (string) $id;
                $path = (string) $path;
                if (! isset($automaticIds[$id]) || str_starts_with($path, 'custom.')) {
                    $incomingAnchors[$id] ??= $path;
                }
            }
            $incoming['anchors'] = $incomingAnchors;

            $normalized = $this->mapping->validate($locked, $incoming);
            $report = $locked->validation_report ?? [];
            if (! is_array($report['analysis'] ?? null)) {
                throw new DocumentFormatException('FORMAT_ANALYSIS_REQUIRED');
            }
            $hasRenderableMapping = ($normalized['anchors'] ?? []) !== [] || ($normalized['placeholders'] ?? []) !== [];
            $report['status'] = $hasRenderableMapping ? 'mapping_ready' : 'analysis_complete';
            unset($report['sample']);
            $locked->forceFill([
                'mapping' => $normalized,
                'schema_version' => 2,
                'renderer' => 'institutional-v1',
                'validation_report' => $report,
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
