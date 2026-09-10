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
        $this->assertAdmin($actor);

        return DB::transaction(function () use ($version, $mapping): FormatVersion {
            $locked = FormatVersion::query()->with(['format', 'sourceFile'])->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($locked->published_at !== null || $locked->format?->kind !== InstitutionalFormatKind::Institutional) {
                throw new DocumentFormatException('FORMAT_MAPPING_STATE_INVALID');
            }
            $normalized = $this->mapping->validate($locked, $mapping);
            $report = $locked->validation_report ?? [];
            if (! is_array($report['analysis'] ?? null)) {
                throw new DocumentFormatException('FORMAT_ANALYSIS_REQUIRED');
            }
            $report['status'] = 'mapping_ready';
            unset($report['sample']);

            $locked->forceFill([
                'mapping' => $normalized,
                'schema_version' => 1,
                'renderer' => 'institutional-v1',
                'validation_report' => $report,
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
