<?php

namespace App\Services\Documents;

use App\Enums\InstitutionalFormatKind;
use App\Enums\InstitutionalFormatStatus;
use App\Exceptions\DocumentFormatException;
use App\Models\FormatVersion;
use App\Models\InstitutionalFormat;
use App\Models\PlanningRequest;

final class PlanningFormatResolver
{
    public function resolve(PlanningRequest $request): FormatVersion
    {
        // Un formato explícito sólo representa una elección de EXPORTACIÓN.
        // Nunca debe condicionar la generación pedagógica de la planeación.
        if ($request->format_version_id !== null) {
            $version = FormatVersion::query()->with('format')->find($request->format_version_id);
            if (! $version) {
                throw new DocumentFormatException('PLANNING_FORMAT_VERSION_NOT_USABLE');
            }

            // Algunos formatos institucionales globales se cargaron únicamente
            // como ejemplos para analizar estructuras reales. Nunca deben
            // convertirse en la salida de una planeación del docente.
            if ($this->isAnalysisOnlyExample($version)) {
                return $this->standard();
            }

            if (! $this->isUsableFor($version, $request->owner_id)) {
                throw new DocumentFormatException('PLANNING_FORMAT_VERSION_NOT_USABLE');
            }

            return $version;
        }

        // Sin elección explícita siempre usamos el formato general estándar.
        // La antigua preferencia del grupo no debe volver a acoplar formato y generación.
        return $this->standard();
    }

    private function standard(): FormatVersion
    {
        // El formato estándar es infraestructura propia de la plataforma.
        // Si una instalación aún no lo tiene, lo creamos de forma idempotente
        // en lugar de obligar al docente a seleccionar o configurar un formato.
        $ensured = app(\App\Actions\Documents\EnsureStandardFormat::class)->execute();
        $version = $ensured['version']->fresh('format');

        if (! $version || $version->published_at === null) {
            throw new DocumentFormatException('PLANNING_STANDARD_FORMAT_VERSION_MISSING');
        }

        return $version;
    }

    private function isAnalysisOnlyExample(FormatVersion $version): bool
    {
        return $version->format?->kind === InstitutionalFormatKind::Institutional
            && data_get($version->validation_report, 'analysis.source_content_mode') === 'filled_example';
    }

    private function isUsableFor(FormatVersion $version, int $ownerId): bool
    {
        $format = $version->format;
        if ($version->published_at === null
            || $format === null
            || $format->status !== InstitutionalFormatStatus::Ready) {
            return false;
        }

        return match ($format->kind) {
            InstitutionalFormatKind::Standard => $format->owner_id === null,
            InstitutionalFormatKind::Institutional => $format->owner_id !== null
                && (int) $format->owner_id === $ownerId,
        };
    }
}
