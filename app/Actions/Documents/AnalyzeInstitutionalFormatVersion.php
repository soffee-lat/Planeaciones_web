<?php

namespace App\Actions\Documents;

use App\Enums\InstitutionalFormatKind;
use App\Enums\InstitutionalFormatStatus;
use App\Enums\RoleCode;
use App\Exceptions\DocumentFormatException;
use App\Models\FormatVersion;
use App\Models\User;
use App\Services\Documents\InstitutionalFormatSourceInspector;
use Illuminate\Support\Facades\DB;

final class AnalyzeInstitutionalFormatVersion
{
    public function __construct(private InstitutionalFormatSourceInspector $inspector) {}

    public function execute(FormatVersion $version, User $actor): FormatVersion
    {
        $this->assertAdmin($actor);
        $version->loadMissing(['format', 'sourceFile']);
        if ($version->published_at !== null || $version->format?->kind !== InstitutionalFormatKind::Institutional) {
            throw new DocumentFormatException('FORMAT_ANALYSIS_STATE_INVALID');
        }
        if (! $version->sourceFile
            || (int) $version->sourceFile->owner_id !== (int) $version->format->owner_id) {
            throw new DocumentFormatException('FORMAT_VERSION_SOURCE_NOT_READY');
        }

        try {
            $analysis = $this->inspector->inspect($version->sourceFile);
        } catch (DocumentFormatException $e) {
            DB::transaction(function () use ($version, $e): void {
                $locked = FormatVersion::query()->with('format')->whereKey($version->id)->lockForUpdate()->firstOrFail();
                if ($locked->published_at === null && $locked->format?->kind === InstitutionalFormatKind::Institutional) {
                    $locked->forceFill([
                        'validation_report' => [
                            'status' => 'unsupported',
                            'analysis' => ['error_code' => $e->getMessage()],
                        ],
                    ])->save();
                    $locked->format->forceFill(['status' => InstitutionalFormatStatus::Unsupported->value])->save();
                }
            }, attempts: 3);
            throw $e;
        }

        return DB::transaction(function () use ($version, $analysis): FormatVersion {
            $locked = FormatVersion::query()->with(['format', 'sourceFile'])->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($locked->published_at !== null
                || $locked->format?->kind !== InstitutionalFormatKind::Institutional
                || ! $locked->sourceFile
                || $locked->sourceFile->sha256 !== $analysis['source_sha256']) {
                throw new DocumentFormatException('FORMAT_ANALYSIS_STALE');
            }
            $locked->forceFill([
                'mapping' => ['schema_version' => 1, 'placeholders' => (object) []],
                'schema_version' => 1,
                'renderer' => 'institutional-v1',
                'validation_report' => [
                    'status' => 'analysis_complete',
                    'analysis' => $analysis,
                ],
            ])->save();
            $locked->format->forceFill(['status' => InstitutionalFormatStatus::Configuring->value])->save();

            return $locked->fresh(['format', 'sourceFile']);
        }, attempts: 3);
    }

    private function assertAdmin(User $actor): void
    {
        if ($actor->status !== 'active' || ! $actor->hasVerifiedEmail() || ! $actor->hasRole(RoleCode::Administrator)) {
            throw new DocumentFormatException('FORMAT_VERSION_ADMIN_REQUIRED');
        }
    }
}
