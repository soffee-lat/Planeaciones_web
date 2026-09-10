<?php

namespace App\Actions\Documents;

use App\Enums\FormatSampleStatus;
use App\Enums\RoleCode;
use App\Exceptions\DocumentFormatException;
use App\Models\FormatVersion;
use App\Models\FormatVersionSample;
use App\Models\User;
use App\Services\Documents\InstitutionalDocumentRenderer;
use App\Services\Documents\InstitutionalFormatMapping;
use App\Support\AI\CanonicalJson;
use Illuminate\Support\Facades\DB;

final class ReviewInstitutionalFormatSample
{
    public function __construct(private InstitutionalFormatMapping $mapping) {}

    public function approve(FormatVersionSample $sample, User $actor, ?string $note = null): FormatVersionSample
    {
        return $this->review($sample, $actor, true, $note);
    }

    public function reject(FormatVersionSample $sample, User $actor, string $note): FormatVersionSample
    {
        return $this->review($sample, $actor, false, $note);
    }

    private function review(FormatVersionSample $sample, User $actor, bool $approved, ?string $note): FormatVersionSample
    {
        $this->assertAdmin($actor);
        $note = trim((string) $note);
        if (! $approved && mb_strlen($note) < 3) {
            throw new DocumentFormatException('FORMAT_SAMPLE_REJECTION_NOTE_REQUIRED');
        }
        if (mb_strlen($note) > 2000) {
            throw new DocumentFormatException('FORMAT_SAMPLE_REVIEW_NOTE_TOO_LONG');
        }

        return DB::transaction(function () use ($sample, $actor, $approved, $note): FormatVersionSample {
            $lockedSample = FormatVersionSample::query()->whereKey($sample->id)->lockForUpdate()->firstOrFail();
            if ($lockedSample->status !== FormatSampleStatus::Pending) {
                if (($approved && $lockedSample->status === FormatSampleStatus::Approved)
                    || (! $approved && $lockedSample->status === FormatSampleStatus::Rejected)) {
                    return $lockedSample->fresh(['formatVersion', 'docxFile', 'pdfFile']);
                }
                throw new DocumentFormatException('FORMAT_SAMPLE_ALREADY_REVIEWED');
            }

            $version = FormatVersion::query()->with(['format', 'sourceFile'])->whereKey($lockedSample->format_version_id)->lockForUpdate()->firstOrFail();
            if ($version->published_at !== null || ! $version->sourceFile) {
                throw new DocumentFormatException('FORMAT_SAMPLE_STATE_INVALID');
            }
            $normalized = $this->mapping->validate($version, $version->mapping);
            $fingerprint = CanonicalJson::hash([
                'source_file_id' => (int) $version->source_file_id,
                'source_sha256' => $version->sourceFile->sha256,
                'mapping' => $normalized,
                'renderer_version' => InstitutionalDocumentRenderer::RENDERER_VERSION,
            ]);
            if ($fingerprint !== $lockedSample->fingerprint
                || (int) $lockedSample->source_file_id !== (int) $version->source_file_id
                || CanonicalJson::hash($lockedSample->mapping_snapshot) !== CanonicalJson::hash($normalized)) {
                throw new DocumentFormatException('FORMAT_SAMPLE_STALE');
            }

            $status = $approved ? FormatSampleStatus::Approved : FormatSampleStatus::Rejected;
            $reviewedAt = now();

            // Para aprobación, PostgreSQL valida inmediatamente que el reporte de
            // FormatVersion apunte a esta muestra exacta. Actualizamos primero el
            // reporte dentro de la misma transacción; si luego falla la muestra,
            // todo se revierte atómicamente.
            $report = $version->validation_report ?? [];
            $report['status'] = $approved ? 'approved' : 'sample_rejected';
            $report['sample'] = [
                'id' => (int) $lockedSample->id,
                'fingerprint' => $lockedSample->fingerprint,
                'renderer_version' => $lockedSample->renderer_version,
                'reviewed_by' => (int) $actor->id,
                'reviewed_at' => $reviewedAt->toIso8601String(),
                'review_note' => $note === '' ? null : $note,
            ];
            $version->forceFill(['validation_report' => $report])->save();

            $lockedSample->forceFill([
                'status' => $status->value,
                'reviewed_by' => $actor->id,
                'review_note' => $note === '' ? null : $note,
                'reviewed_at' => $reviewedAt,
            ])->save();

            return $lockedSample->fresh(['formatVersion', 'docxFile', 'pdfFile']);
        }, attempts: 3);
    }

    private function assertAdmin(User $actor): void
    {
        if ($actor->status !== 'active' || ! $actor->hasVerifiedEmail() || ! $actor->hasRole(RoleCode::Administrator)) {
            throw new DocumentFormatException('FORMAT_VERSION_ADMIN_REQUIRED');
        }
    }
}
