<?php

namespace App\Actions\Documents;

use App\Enums\FormatSampleStatus;
use App\Enums\InstitutionalFormatKind;
use App\Enums\RoleCode;
use App\Exceptions\DocumentFormatException;
use App\Models\FormatVersion;
use App\Models\FormatVersionSample;
use App\Models\User;
use App\Services\Documents\InstitutionalDocumentRenderer;
use App\Services\Documents\InstitutionalDynamicFieldResolver;
use App\Services\Documents\InstitutionalFormatMapping;
use App\Services\Documents\InstitutionalFormatSampleStorage;
use App\Support\AI\CanonicalJson;
use Illuminate\Support\Facades\DB;

final class RenderInstitutionalFormatSample
{
    public function __construct(
        private InstitutionalDocumentRenderer $renderer,
        private InstitutionalFormatMapping $mapping,
        private InstitutionalDynamicFieldResolver $dynamicFields,
        private InstitutionalFormatSampleStorage $storage,
    ) {}

    public function execute(FormatVersion $version, User $actor): FormatVersionSample
    {
        $version = FormatVersion::query()->with(['format', 'sourceFile'])->findOrFail($version->id);
        $this->assertOwner($version, $actor);
        if ($version->published_at !== null || $version->format?->kind !== InstitutionalFormatKind::Institutional || ! $version->sourceFile) {
            throw new DocumentFormatException('FORMAT_SAMPLE_STATE_INVALID');
        }

        $normalized = $this->normalizedMapping($version);
        $fingerprint = $this->fingerprint($version, $normalized);
        $existing = FormatVersionSample::query()
            ->where('format_version_id', $version->id)
            ->where('fingerprint', $fingerprint)
            ->first();
        if ($existing) {
            return $existing->fresh(['docxFile', 'pdfFile']);
        }

        $rendered = $this->renderer->renderSample($version);
        $files = $this->storage->persist($version, $fingerprint, $rendered['docx'], $rendered['pdf']);

        return DB::transaction(function () use ($version, $actor, $normalized, $fingerprint, $files): FormatVersionSample {
            $locked = FormatVersion::query()->with(['format', 'sourceFile'])->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($locked->published_at !== null || ! $locked->sourceFile) {
                throw new DocumentFormatException('FORMAT_SAMPLE_STATE_INVALID');
            }

            $current = $this->normalizedMapping($locked);
            $currentFingerprint = $this->fingerprint($locked, $current);
            if ($currentFingerprint !== $fingerprint || $current !== $normalized) {
                throw new DocumentFormatException('FORMAT_SAMPLE_STALE');
            }

            $sample = FormatVersionSample::query()
                ->where('format_version_id', $locked->id)
                ->where('fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();
            if (! $sample) {
                $sample = FormatVersionSample::query()->create([
                    'format_version_id' => $locked->id,
                    'source_file_id' => $locked->source_file_id,
                    'mapping_snapshot' => $normalized,
                    'renderer_version' => InstitutionalDocumentRenderer::RENDERER_VERSION,
                    'fingerprint' => $fingerprint,
                    'docx_file_id' => $files['docx']->id,
                    'pdf_file_id' => $files['pdf']->id,
                    'status' => FormatSampleStatus::Pending->value,
                    'created_by' => $actor->id,
                    'reviewed_by' => null,
                    'review_note' => null,
                    'reviewed_at' => null,
                ]);
            }

            $report = $locked->validation_report ?? [];
            $report['status'] = 'sample_ready';
            $report['sample'] = [
                'id' => (int) $sample->id,
                'fingerprint' => $fingerprint,
                'renderer_version' => InstitutionalDocumentRenderer::RENDERER_VERSION,
            ];
            $locked->forceFill(['validation_report' => $report])->save();

            return $sample->fresh(['formatVersion', 'docxFile', 'pdfFile']);
        }, attempts: 3);
    }

    /** @return array<string,mixed> */
    private function normalizedMapping(FormatVersion $version): array
    {
        $raw = is_array($version->mapping) ? $version->mapping : [];
        return $this->mapping->validate($version, $this->dynamicFields->augment($version, $raw));
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
