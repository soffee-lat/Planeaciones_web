<?php

namespace App\Services\Documents;

use App\Data\Documents\RenderedArtifact;
use App\Exceptions\DocumentRenderException;
use App\Models\DocumentVersion;
use App\Models\FormatVersion;

final class DocumentRendererRegistry
{
    public function __construct(
        private StandardDocumentRenderer $standard,
        private InstitutionalDocumentRenderer $institutional,
    ) {}

    public function supports(FormatVersion $formatVersion): bool
    {
        return in_array($formatVersion->renderer, [
            StandardDocumentRenderer::FORMAT_RENDERER,
            InstitutionalDocumentRenderer::FORMAT_RENDERER,
        ], true);
    }

    public function rendererVersion(FormatVersion $formatVersion): string
    {
        if (! $this->supports($formatVersion)) {
            throw new DocumentRenderException('DOCUMENT_RENDERER_NOT_SUPPORTED', $formatVersion->renderer);
        }

        return match ($formatVersion->renderer) {
            StandardDocumentRenderer::FORMAT_RENDERER => StandardDocumentRenderer::RENDERER_VERSION,
            InstitutionalDocumentRenderer::FORMAT_RENDERER => InstitutionalDocumentRenderer::RENDERER_VERSION,
            default => throw new DocumentRenderException('DOCUMENT_RENDERER_NOT_SUPPORTED', $formatVersion->renderer),
        };
    }

    /** @return array{0:RenderedArtifact,1:RenderedArtifact} */
    public function render(DocumentVersion $version, FormatVersion $formatVersion): array
    {
        if (! $this->supports($formatVersion)) {
            throw new DocumentRenderException('DOCUMENT_RENDERER_NOT_SUPPORTED', $formatVersion->renderer);
        }

        return match ($formatVersion->renderer) {
            StandardDocumentRenderer::FORMAT_RENDERER => $this->standard->render($version, $formatVersion),
            InstitutionalDocumentRenderer::FORMAT_RENDERER => $this->institutional->render($version, $formatVersion),
            default => throw new DocumentRenderException('DOCUMENT_RENDERER_NOT_SUPPORTED', $formatVersion->renderer),
        };
    }
}
