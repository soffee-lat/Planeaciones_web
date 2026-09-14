<?php

namespace App\Services\Documents;

use App\Data\Documents\RenderedArtifact;
use App\Enums\DocumentOutputFormat;
use App\Exceptions\DocumentRenderException;
use App\Models\DocumentVersion;
use App\Models\FormatVersion;
use App\Services\AI\CanonicalPlanValidator;
use App\Support\AI\CanonicalJson;

final class StandardDocumentRenderer
{
    public const FORMAT_RENDERER = 'standard-v1';
    // Se conserva el identificador por compatibilidad con entregas/pruebas
    // existentes; la presentación interna corresponde al Standard v2.
    public const RENDERER_VERSION = 'standard-v1.0.0';

    public function __construct(
        private CanonicalPlanValidator $validator,
        private CanonicalPlanDocumentBuilder $builder,
        private StandardDocxRenderer $docx,
        private StandardPdfRenderer $pdf,
    ) {}

    /** @return array{0:RenderedArtifact,1:RenderedArtifact} */
    public function render(DocumentVersion $version, FormatVersion $formatVersion): array
    {
        if ($formatVersion->renderer !== self::FORMAT_RENDERER || $formatVersion->published_at === null) {
            throw new DocumentRenderException('DOCUMENT_RENDERER_NOT_SUPPORTED', $formatVersion->renderer);
        }

        $canonical = $this->validator->validate($version->content)->toArray();
        if (CanonicalJson::hash($canonical) !== $version->content_hash) {
            throw new DocumentRenderException('DOCUMENT_RENDER_SOURCE_HASH_MISMATCH');
        }

        $blocks = $this->builder->build($canonical);
        if ($blocks === []) {
            throw new DocumentRenderException('DOCUMENT_RENDER_EMPTY_CONTENT');
        }

        $docxBytes = $this->docx->render($blocks);
        $pdfBytes = $this->pdf->render($blocks);
        if (! str_starts_with($docxBytes, "PK\x03\x04") || strlen($docxBytes) < 800) {
            throw new DocumentRenderException('DOCUMENT_RENDER_DOCX_INVALID');
        }
        if (! str_starts_with($pdfBytes, '%PDF-1.4') || ! str_ends_with($pdfBytes, "%%EOF\n") || strlen($pdfBytes) < 500) {
            throw new DocumentRenderException('DOCUMENT_RENDER_PDF_INVALID');
        }

        return [
            new RenderedArtifact(
                DocumentOutputFormat::Docx,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'docx',
                $docxBytes,
            ),
            new RenderedArtifact(DocumentOutputFormat::Pdf, 'application/pdf', 'pdf', $pdfBytes),
        ];
    }
}
