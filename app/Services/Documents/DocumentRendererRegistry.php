<?php

namespace App\Services\Documents;

use App\Data\Documents\RenderedArtifact;
use App\Exceptions\DocumentRenderException;
use App\Models\DocumentVersion;
use App\Models\FormatVersion;

final class DocumentRendererRegistry
{
    public function __construct(private StandardDocumentRenderer $standard) {}

    public function supports(FormatVersion $formatVersion): bool
    {
        return $formatVersion->renderer === StandardDocumentRenderer::FORMAT_RENDERER;
    }

    public function rendererVersion(FormatVersion $formatVersion): string
    {
        if (! $this->supports($formatVersion)) {
            throw new DocumentRenderException('DOCUMENT_RENDERER_NOT_SUPPORTED', $formatVersion->renderer);
        }

        return StandardDocumentRenderer::RENDERER_VERSION;
    }

    /** @return array{0:RenderedArtifact,1:RenderedArtifact} */
    public function render(DocumentVersion $version, FormatVersion $formatVersion): array
    {
        if (! $this->supports($formatVersion)) {
            throw new DocumentRenderException('DOCUMENT_RENDERER_NOT_SUPPORTED', $formatVersion->renderer);
        }

        return $this->standard->render($version, $formatVersion);
    }
}
