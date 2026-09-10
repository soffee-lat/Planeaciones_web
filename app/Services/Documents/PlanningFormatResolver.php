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
        if ($request->format_version_id !== null) {
            $version = FormatVersion::query()->with('format')->find($request->format_version_id);
            if (! $version || ! $this->isUsableFor($version, $request->owner_id)) {
                throw new DocumentFormatException('PLANNING_FORMAT_VERSION_NOT_USABLE');
            }

            return $version;
        }

        $request->loadMissing('group.profile');
        $preferredId = $request->group?->profile?->preferred_format_id;
        if ($preferredId !== null) {
            $preferred = InstitutionalFormat::query()
                ->whereKey($preferredId)
                ->where('status', InstitutionalFormatStatus::Ready->value)
                ->where(function ($query) use ($request): void {
                    $query->whereNull('owner_id')->orWhere('owner_id', $request->owner_id);
                })
                ->first();

            if ($preferred) {
                $version = FormatVersion::query()
                    ->where('format_id', $preferred->id)
                    ->whereNotNull('published_at')
                    ->orderByDesc('number')
                    ->first();
                if ($version) {
                    return $version->setRelation('format', $preferred);
                }
            }
        }

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
