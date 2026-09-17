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
    /** @return array<int,string> */
    public function exportOptionsFor(int $ownerId): array
    {
        $formats = InstitutionalFormat::query()
            ->where('status', InstitutionalFormatStatus::Ready->value)
            ->where(function ($query) use ($ownerId): void {
                $query->whereNull('owner_id')->orWhere('owner_id', $ownerId);
            })
            ->with('publishedVersions')
            ->orderBy('name')
            ->get();

        $options = [];
        foreach ($formats as $format) {
            $version = $format->publishedVersions->first();
            if (! $version) {
                continue;
            }

            $suffix = $format->kind === InstitutionalFormatKind::Standard
                ? ' · estándar'
                : ' · institucional';
            $options[(int) $version->id] = $format->name . $suffix;
        }

        return $options;
    }

    public function resolve(PlanningRequest $request): FormatVersion
    {
        // Un formato explícito sólo representa una elección de EXPORTACIÓN.
        // Nunca debe condicionar la generación pedagógica de la planeación.
        if ($request->format_version_id !== null) {
            $version = FormatVersion::query()->with('format')->find($request->format_version_id);
            if (! $version || ! $this->isUsableFor($version, $request->owner_id)) {
                throw new DocumentFormatException('PLANNING_FORMAT_VERSION_NOT_USABLE');
            }

            return $version;
        }

        // Sin elección explícita siempre usamos el estándar global. La antigua
        // preferencia del grupo no debe volver a acoplar formato y generación.
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

    private function isUsableFor(FormatVersion $version, int $ownerId): bool
    {
        $format = $version->format;

        return $version->published_at !== null
            && $format !== null
            && $format->status === InstitutionalFormatStatus::Ready
            && ($format->owner_id === null || $format->owner_id === $ownerId);
    }
}
