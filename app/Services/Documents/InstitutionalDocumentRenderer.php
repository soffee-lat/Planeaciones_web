<?php

namespace App\Services\Documents;

use App\Data\Documents\RenderedArtifact;
use App\Enums\DocumentOutputFormat;
use App\Enums\FileCategory;
use App\Enums\FileScanStatus;
use App\Exceptions\DocumentFormatException;
use App\Exceptions\DocumentRenderException;
use App\Models\DocumentVersion;
use App\Models\FormatVersion;
use App\Services\AI\CanonicalPlanValidator;
use App\Support\AI\CanonicalJson;
use Illuminate\Support\Facades\Storage;

final class InstitutionalDocumentRenderer
{
    public const FORMAT_RENDERER = 'institutional-v1';
    public const RENDERER_VERSION = 'institutional-v1.2.0';

    public function __construct(
        private CanonicalPlanValidator $validator,
        private InstitutionalFormatMapping $mapping,
        private InstitutionalDynamicFieldResolver $dynamicFields,
        private InstitutionalDocxTemplateEngine $template,
        private StandardPdfRenderer $pdf,
    ) {}

    /** @return array{0:RenderedArtifact,1:RenderedArtifact} */
    public function render(DocumentVersion $version, FormatVersion $formatVersion): array
    {
        if ($formatVersion->renderer !== self::FORMAT_RENDERER || $formatVersion->published_at === null) {
            throw new DocumentRenderException('DOCUMENT_RENDERER_NOT_SUPPORTED', $formatVersion->renderer);
        }
        $canonical = $this->validator->validate($version->content)->toArray();
        if (CanonicalJson::hash($canonical) !== $version->content_hash) throw new DocumentRenderException('DOCUMENT_RENDER_SOURCE_HASH_MISMATCH');
        try {
            [$source, $sourceBytes, $normalized] = $this->source($formatVersion);
            $docxBytes = $this->template->render($sourceBytes, $this->mapping->values($canonical, $normalized), (array) ($formatVersion->validation_report['analysis'] ?? []));
            $pdfBytes = $this->pdf->render($this->template->textBlocks($docxBytes));
        } catch (DocumentFormatException $e) {
            throw new DocumentRenderException('DOCUMENT_RENDER_INSTITUTIONAL_FORMAT_INVALID', $e->getMessage());
        }
        if (! str_starts_with($docxBytes, "PK\x03\x04") || strlen($docxBytes) < 300) throw new DocumentRenderException('DOCUMENT_RENDER_DOCX_INVALID');
        if (! str_starts_with($pdfBytes, '%PDF-1.4') || ! str_ends_with($pdfBytes, "%%EOF\n") || strlen($pdfBytes) < 500) throw new DocumentRenderException('DOCUMENT_RENDER_PDF_INVALID');
        return [
            new RenderedArtifact(DocumentOutputFormat::Docx, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx', $docxBytes),
            new RenderedArtifact(DocumentOutputFormat::Pdf, 'application/pdf', 'pdf', $pdfBytes),
        ];
    }

    /** @return array{docx:string,pdf:string} */
    public function renderSample(FormatVersion $formatVersion): array
    {
        [, $sourceBytes, $normalized] = $this->source($formatVersion);
        $docx = $this->template->render($sourceBytes, $this->mapping->sampleValues($normalized), (array) ($formatVersion->validation_report['analysis'] ?? []));
        return ['docx' => $docx, 'pdf' => $this->pdf->render($this->template->textBlocks($docx))];
    }

    /** @return array{0:mixed,1:string,2:array<string,mixed>} */
    private function source(FormatVersion $formatVersion): array
    {
        $formatVersion->loadMissing(['format', 'sourceFile']);
        $source = $formatVersion->sourceFile;
        if ($formatVersion->renderer !== self::FORMAT_RENDERER || ! $source || $source->category !== FileCategory::InstitutionalFormat
            || $source->scan_status !== FileScanStatus::Clean || $source->purged_at !== null
            || (int) $source->owner_id !== (int) $formatVersion->format?->owner_id) {
            throw new DocumentFormatException('FORMAT_SAMPLE_RENDERER_NOT_SUPPORTED');
        }
        $rawMapping = is_array($formatVersion->mapping) ? $formatVersion->mapping : [];
        $normalized = $this->mapping->validate(
            $formatVersion,
            $this->dynamicFields->augment($formatVersion, $rawMapping),
        );
        $storage = Storage::disk($source->disk);
        if (! $storage->exists($source->path)) throw new DocumentFormatException('FORMAT_SOURCE_BYTES_MISSING');
        $bytes = $storage->get($source->path);
        if (! is_string($bytes) || hash('sha256', $bytes) !== $source->sha256) throw new DocumentFormatException('FORMAT_SOURCE_HASH_MISMATCH');
        return [$source, $bytes, $normalized];
    }
}
