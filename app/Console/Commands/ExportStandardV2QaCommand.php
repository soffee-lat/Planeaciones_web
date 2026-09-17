<?php

namespace App\Console\Commands;

use App\Enums\PlanningRequestStatus;
use App\Models\FormatVersion;
use App\Models\PlanningRequest;
use App\Services\Documents\StandardDocumentRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ExportStandardV2QaCommand extends Command
{
    protected $signature = 'validation:export-standard-v2-qa
        {request_id : ID de una PlanningRequest con planeación canónica aprobada}';

    protected $description = 'Genera copias QA DOCX/PDF del Standard v2 sin cambiar estado, formato, revisión ni consumo de la planeación.';

    public function handle(StandardDocumentRenderer $renderer): int
    {
        $requestId = (int) $this->argument('request_id');
        if ($requestId < 1) {
            $this->error('STANDARD_V2_QA_REQUEST_ID_INVALID');
            return self::FAILURE;
        }

        $request = PlanningRequest::query()
            ->with('document.currentVersion')
            ->find($requestId);
        if (! $request) {
            $this->error('STANDARD_V2_QA_REQUEST_NOT_FOUND');
            return self::FAILURE;
        }

        $allowedStatuses = [
            PlanningRequestStatus::APROBADA,
            PlanningRequestStatus::GENERANDO_DOCUMENTO,
            PlanningRequestStatus::LISTA_PARA_ENTREGAR,
            PlanningRequestStatus::ENTREGADA,
            PlanningRequestStatus::COMPLETADA,
        ];
        if (! in_array($request->status, $allowedStatuses, true)) {
            $this->error('STANDARD_V2_QA_REQUEST_NOT_APPROVED');
            return self::FAILURE;
        }

        $version = $request->document?->currentVersion;
        if (! $version) {
            $this->error('STANDARD_V2_QA_CURRENT_VERSION_REQUIRED');
            return self::FAILURE;
        }

        if (! $request->approvals()->where('version_id', $version->id)->exists()) {
            $this->error('STANDARD_V2_QA_APPROVAL_REQUIRED');
            return self::FAILURE;
        }

        // Este FormatVersion vive sólo en memoria. El objetivo es inspeccionar
        // exactamente el renderer estándar sin seleccionar ni persistir un
        // formato sobre la PlanningRequest.
        $formatVersion = new FormatVersion();
        $formatVersion->forceFill([
            'renderer' => StandardDocumentRenderer::FORMAT_RENDERER,
            'published_at' => now(),
        ]);

        try {
            $artifacts = $renderer->render($version, $formatVersion);
            $disk = Storage::disk('private');
            $basePath = sprintf(
                'qa/standard-v2/request-%d/version-%d',
                $request->id,
                $version->id,
            );

            $rows = [];
            foreach ($artifacts as $artifact) {
                $path = $basePath . '/planeacion-standard-v2.' . $artifact->extension;
                $disk->put($path, $artifact->bytes);
                $rows[] = [
                    strtoupper($artifact->format->value),
                    $path,
                    (string) $artifact->sizeBytes(),
                    $artifact->sha256(),
                ];
            }
        } catch (Throwable $error) {
            report($error);
            $this->error('STANDARD_V2_QA_EXPORT_FAILED');
            return self::FAILURE;
        }

        $this->info('Standard v2 QA exportado.');
        $this->line('request_id=' . $request->id);
        $this->line('document_version_id=' . $version->id);
        $this->line('source_content_hash=' . $version->content_hash);
        $this->line('renderer_version=' . StandardDocumentRenderer::RENDERER_VERSION);
        $this->table(['Formato', 'Ruta privada', 'Bytes', 'SHA-256'], $rows);
        $this->warn('Validación no invasiva: no se cambió estado, formato de exportación, input_revision, reservas ni historial de IA.');

        return self::SUCCESS;
    }
}
