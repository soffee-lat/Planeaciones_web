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
        {request_id? : ID de una PlanningRequest con planeación canónica aprobada}';

    protected $description = 'Lista candidatas o genera copias QA DOCX/PDF del Standard v2 sin cambiar estado, formato, revisión ni consumo de la planeación.';

    public function handle(StandardDocumentRenderer $renderer): int
    {
        $argument = $this->argument('request_id');
        if ($argument === null || trim((string) $argument) === '') {
            return $this->listCandidates();
        }

        $requestId = (int) $argument;
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

        if (! $this->isAllowedStatus($request->status)) {
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

    private function listCandidates(): int
    {
        $statuses = $this->allowedStatuses();
        $requests = PlanningRequest::query()
            ->with(['document.currentVersion', 'approvals'])
            ->whereIn('status', array_map(fn (PlanningRequestStatus $status): string => $status->value, $statuses))
            ->whereHas('document.currentVersion')
            ->whereHas('approvals')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->filter(function (PlanningRequest $request): bool {
                $versionId = $request->document?->current_version_id;
                return $versionId !== null
                    && $request->approvals->contains(fn ($approval): bool => (int) $approval->version_id === (int) $versionId);
            })
            ->take(20)
            ->values();

        if ($requests->isEmpty()) {
            $this->warn('No hay planeaciones aprobadas elegibles para QA de Standard v2.');
            $this->line('Genera/aprueba una planeación y vuelve a ejecutar este comando.');
            return self::SUCCESS;
        }

        $this->info('Planeaciones elegibles para QA de Standard v2:');
        $this->table(
            ['request_id', 'Estado', 'Planeación', 'Versión documental'],
            $requests->map(function (PlanningRequest $request): array {
                $version = $request->document?->currentVersion;
                return [
                    (string) $request->id,
                    $request->status->value,
                    trim((string) ($request->project ?: $request->topic ?: '—')),
                    $version ? (string) $version->id : '—',
                ];
            })->all(),
        );
        $this->line('Ejemplo: php artisan validation:export-standard-v2-qa ' . $requests->first()->id);

        return self::SUCCESS;
    }

    private function isAllowedStatus(PlanningRequestStatus $status): bool
    {
        return in_array($status, $this->allowedStatuses(), true);
    }

    /** @return list<PlanningRequestStatus> */
    private function allowedStatuses(): array
    {
        return [
            PlanningRequestStatus::APROBADA,
            PlanningRequestStatus::GENERANDO_DOCUMENTO,
            PlanningRequestStatus::LISTA_PARA_ENTREGAR,
            PlanningRequestStatus::ENTREGADA,
            PlanningRequestStatus::COMPLETADA,
        ];
    }
}
