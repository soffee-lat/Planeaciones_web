<?php

namespace App\Actions\Documents;

use App\Enums\FileCategory;
use App\Enums\FileScanStatus;
use App\Enums\InstitutionalFormatKind;
use App\Enums\InstitutionalFormatStatus;
use App\Enums\RoleCode;
use App\Exceptions\DocumentFormatException;
use App\Models\FormatVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PublishFormatVersion
{
    public function execute(FormatVersion $version, User $actor): FormatVersion
    {
        if ($actor->status !== 'active' || ! $actor->hasVerifiedEmail() || ! $actor->hasRole(RoleCode::Administrator)) {
            throw new DocumentFormatException('FORMAT_VERSION_ADMIN_REQUIRED');
        }

        return DB::transaction(function () use ($version, $actor): FormatVersion {
            $locked = FormatVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing(['format', 'sourceFile']);

            if ($locked->published_at !== null) {
                return $locked;
            }
            if ($locked->format->kind !== InstitutionalFormatKind::Institutional) {
                throw new DocumentFormatException('FORMAT_VERSION_STANDARD_SYSTEM_MANAGED');
            }
            $source = $locked->sourceFile;
            if (! $source
                || $source->category !== FileCategory::InstitutionalFormat
                || $source->scan_status !== FileScanStatus::Clean
                || $source->owner_id !== $locked->format->owner_id) {
                throw new DocumentFormatException('FORMAT_VERSION_SOURCE_NOT_READY');
            }
            if (($locked->validation_report['status'] ?? null) !== 'approved') {
                throw new DocumentFormatException('FORMAT_VERSION_SAMPLE_NOT_APPROVED');
            }
            if (! is_array($locked->mapping) || $locked->mapping === []) {
                throw new DocumentFormatException('FORMAT_VERSION_MAPPING_REQUIRED');
            }

            $locked->format->forceFill(['status' => InstitutionalFormatStatus::Ready->value])->save();
            $locked->forceFill([
                'approved_by' => $actor->id,
                'published_at' => now(),
            ])->save();

            return $locked->fresh(['format', 'sourceFile']);
        }, attempts: 3);
    }
}
