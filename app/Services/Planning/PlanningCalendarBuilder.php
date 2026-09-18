<?php

namespace App\Services\Planning;

use App\Models\GroupSchedule;
use Carbon\CarbonImmutable;

final class PlanningCalendarBuilder
{
    /**
     * Expande el horario habitual a fechas concretas del periodo solicitado.
     * Los bloques no planeables permanecen visibles para que la IA conozca la
     * capacidad real de la jornada, pero se marcan include_in_planning=false.
     *
     * @return array<int,array<string,mixed>>
     */
    public function build(GroupSchedule $schedule, string $startsOn, string $endsOn): array
    {
        $schedule->loadMissing('blocks');

        $start = CarbonImmutable::parse($startsOn)->startOfDay();
        $end = CarbonImmutable::parse($endsOn)->startOfDay();

        $validFrom = $schedule->valid_from?->toImmutable()->startOfDay();
        $validUntil = $schedule->valid_until?->toImmutable()->startOfDay();

        $blocksByDay = $schedule->blocks->groupBy('day_of_week');
        $days = [];

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            if ($validFrom && $date->lt($validFrom)) {
                continue;
            }
            if ($validUntil && $date->gt($validUntil)) {
                continue;
            }

            $blocks = $blocksByDay->get($date->isoWeekday());
            if (! $blocks || $blocks->isEmpty()) {
                continue;
            }

            $days[] = [
                'date' => $date->format('Y-m-d'),
                'day_of_week' => $date->isoWeekday(),
                'blocks' => $blocks->values()->map(fn ($block) => [
                    'schedule_block_id' => (int) $block->id,
                    'sequence' => (int) $block->sequence,
                    'starts_at' => substr((string) $block->starts_at, 0, 5),
                    'ends_at' => substr((string) $block->ends_at, 0, 5),
                    'minutes' => $this->minutes((string) $block->starts_at, (string) $block->ends_at),
                    'label' => $block->label,
                    'block_type' => $block->block_type,
                    'responsibility' => $block->responsibility,
                    'include_in_planning' => (bool) $block->include_in_planning,
                    'is_flexible' => (bool) $block->is_flexible,
                    'field_codes' => array_values($block->field_codes ?? []),
                    'notes' => $block->notes,
                ])->all(),
            ];
        }

        return $days;
    }

    private function minutes(string $startsAt, string $endsAt): int
    {
        $start = CarbonImmutable::createFromFormat('H:i:s', strlen($startsAt) === 5 ? $startsAt . ':00' : $startsAt);
        $end = CarbonImmutable::createFromFormat('H:i:s', strlen($endsAt) === 5 ? $endsAt . ':00' : $endsAt);

        return $start->diffInMinutes($end);
    }
}
