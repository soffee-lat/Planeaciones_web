<?php

namespace App\Services\Curriculum;

use App\Models\CurriculumVersion;
use RuntimeException;

/**
 * Gate between editorial/test catalogs and curricula that may be used for
 * real teacher planning.
 *
 * Publication is necessary but not sufficient: the demo catalog is
 * deliberately published in local/test to exercise invariants, so we also
 * reject explicit demo/fictitious markers.
 */
final class ProductionCurriculumPolicy
{
    public const NOT_READY = 'CURRICULUM_NOT_PRODUCTION_READY';

    public function isPlanningEligible(CurriculumVersion $version): bool
    {
        $version->loadMissing('curriculum');
        $curriculum = $version->curriculum;

        if (! $curriculum || $version->published_at === null || blank($version->checksum)) {
            return false;
        }

        $identity = [
            $curriculum->code,
            $curriculum->name,
            $curriculum->country_code,
            $curriculum->educational_level,
            $curriculum->description,
            $version->label,
            $version->source_reference,
        ];

        foreach ($identity as $value) {
            if ($this->containsBlockedMarker($value)) {
                return false;
            }
        }

        return true;
    }

    public function assertPlanningEligible(CurriculumVersion $version): void
    {
        if (! $this->isPlanningEligible($version)) {
            throw new RuntimeException(self::NOT_READY);
        }
    }

    /**
     * Defends generation for already-confirmed historical requests too.
     * New confirmations should already have passed assertPlanningEligible().
     *
     * @param array<string,mixed> $snapshot
     */
    public function assertSnapshotEligible(array $snapshot): void
    {
        $curriculum = data_get($snapshot, 'curriculum.curriculum', []);
        $version = data_get($snapshot, 'curriculum.version', []);

        if (! is_array($curriculum) || ! is_array($version)) {
            throw new RuntimeException(self::NOT_READY);
        }

        if (blank($version['published_at'] ?? null) || blank($version['checksum'] ?? null)) {
            throw new RuntimeException(self::NOT_READY);
        }

        foreach ([
            $curriculum['code'] ?? null,
            $curriculum['name'] ?? null,
            $curriculum['country_code'] ?? null,
            $curriculum['educational_level'] ?? null,
            $version['label'] ?? null,
            $version['source_reference'] ?? null,
        ] as $value) {
            if ($this->containsBlockedMarker($value)) {
                throw new RuntimeException(self::NOT_READY);
            }
        }

        foreach (['phase', 'grade'] as $key) {
            $node = data_get($snapshot, 'curriculum.' . $key, []);
            if (is_array($node) && $this->containsBlockedMarker($node['name'] ?? null)) {
                throw new RuntimeException(self::NOT_READY);
            }
        }

        foreach (['formative_fields', 'contents', 'pdas', 'axes'] as $key) {
            $rows = data_get($snapshot, 'curriculum.' . $key, []);
            if (! is_array($rows)) {
                throw new RuntimeException(self::NOT_READY);
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new RuntimeException(self::NOT_READY);
                }
                foreach ($row as $value) {
                    if ($this->containsBlockedMarker($value)) {
                        throw new RuntimeException(self::NOT_READY);
                    }
                }
            }
        }
    }

    /** @return list<int> */
    public function selectableVersionIds(): array
    {
        return CurriculumVersion::query()
            ->with('curriculum')
            ->whereNotNull('published_at')
            ->whereNotNull('checksum')
            ->get()
            ->filter(fn (CurriculumVersion $version): bool => $this->isPlanningEligible($version))
            ->filter(fn (CurriculumVersion $version): bool => (int) ($version->curriculum?->selectable_version_id ?? 0) === (int) $version->id)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function containsBlockedMarker(mixed $value): bool
    {
        return EditorialMarkerDetector::contains($value);
    }
}
