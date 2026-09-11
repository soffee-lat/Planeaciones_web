<?php

namespace App\Actions\Documents;

use App\Enums\FileCategory;
use App\Enums\FileScanStatus;
use App\Enums\FormatSampleStatus;
use App\Enums\InstitutionalFormatKind;
use App\Enums\InstitutionalFormatStatus;
use App\Enums\RoleCode;
use App\Exceptions\DocumentFormatException;
use App\Models\FormatVersion;
use App\Models\FormatVersionSample;
use App\Models\User;
use App\Services\Documents\DocumentRendererRegistry;
use App\Services\Documents\InstitutionalDocumentRenderer;
use App\Services\Documents\InstitutionalFormatMapping;
use App\Support\AI\CanonicalJson;
use Illuminate\Support\Facades\DB;

final class PublishFormatVersion
{
    public function __construct(private DocumentRendererRegistry $renderers, private InstitutionalFormatMapping $mapping) {}

    public function execute(FormatVersion $version, User $actor): FormatVersion
    {
        $version->loadMissing('format');
        $this->assertOwner($version, $actor);

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

            $normalized = $this->mapping->validate($locked, $locked->mapping);
            if (! $this->renderers->supports($locked)) {
                throw new DocumentFormatException('FORMAT_VERSION_RENDERER_NOT_SUPPORTED');
            }

            $sampleId = (int) data_get($locked->validation_report, 'sample.id', 0);
            $sample = $sampleId > 0
                ? FormatVersionSample::query()->whereKey($sampleId)->lockForUpdate()->first()
                : null;
            $fingerprint = $this->fingerprint($locked, $normalized);

            if (! $sample
                || $sample->status !== FormatSampleStatus::Approved
                || (int) $sample->format_version_id !== (int) $locked->id
                || $sample->fingerprint !== $fingerprint
                || CanonicalJson::hash($sample->mapping_snapshot) !== CanonicalJson::hash($normalized)
                || (int) $sample->source_file_id !== (int) $locked->source_file_id) {
                throw new DocumentFormatException('FORMAT_VERSION_SAMPLE_NOT_APPROVED');
            }

            $locked->format->forceFill(['status' => InstitutionalFormatStatus::Ready->value])->save();
            $locked->forceFill(['approved_by' => $actor->id, 'published_at' => now()])->save();

            return $locked->fresh(['format', 'sourceFile']);
        }, attempts: 3);
    }

    /** @param array<string,mixed> $normalized */
    private function fingerprint(FormatVersion $version, array $normalized): string
    {
        return CanonicalJson::hash([
            'source_file_id' => (int) $version->source_file_id,
            'source_sha256' => $version->sourceFile?->sha256,
            'mapping' => $normalized,
            'analysis_hash' => CanonicalJson::hash(
                is_array($version->validation_report['analysis'] ?? null)
                    ? $version->validation_report['analysis']
                    : [],
            ),
            'renderer_version' => InstitutionalDocumentRenderer::RENDERER_VERSION,
        ]);
    }

    private function assertOwner(FormatVersion $version, User $actor): void
    {
        if ($actor->status !== 'active' || ! $actor->hasVerifiedEmail() || ! $actor->hasRole(RoleCode::Customer)
            || (int) $version->format?->owner_id !== (int) $actor->id) {
            throw new DocumentFormatException('FORMAT_OWNER_REQUIRED');
        }
    }
}
