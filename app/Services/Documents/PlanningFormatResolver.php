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
            if ($this->isGlobalInstitutionalExample($version)) {
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
        $standard = InstitutionalFormat::query()
            ->whereNull('owner_id')
            ->where('kind', InstitutionalFormatKind::Standard->value)
            ->where('status', InstitutionalFormatStatus::Ready->value)
            ->first();
        if (! $standard) {
            throw new DocumentFormatException('PLANNING_STANDARD_FORMAT_MISSING');
        }

        $version = FormatVersion::query()
            ->where('format_id', $standard->id)
            ->whereNotNull('published_at')
            ->orderByDesc('number')
            ->first();
        if (! $version) {
            throw new DocumentFormatException('PLANNING_STANDARD_FORMAT_VERSION_MISSING');
        }

        return $version->setRelation('format', $standard);
    }

    private function isGlobalInstitutionalExample(FormatVersion $version): bool
    {
        $format = $version->format;

        return $format !== null
            && $format->owner_id === null
            && $format->kind === InstitutionalFormatKind::Institutional;
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
