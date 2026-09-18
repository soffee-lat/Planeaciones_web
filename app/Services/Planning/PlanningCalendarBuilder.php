<?php

namespace App\Services\Planning;

use App\Models\GroupSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class PlanningCalendarBuilder
{
    /**
     * @return array<string,mixed>
     */
    public function scheduleSnapshot(GroupSchedule $schedule): array
    {
        $schedule->loadMissing('blocks');

        return [
            'schema_version' => 1,
            'revision' => (int) $schedule->revision,
            'name' => (string) $schedule->name,
            'day_starts_at' => substr((string) $schedule->day_starts_at, 0, 5),
            'day_ends_at' => substr((string) $schedule->day_ends_at, 0, 5),
            'blocks' => $schedule->blocks->map(fn ($block) => [
                'id' => (int) $block->id,
                'day_of_week' => (int) $block->day_of_week,
                'sequence' => (int) $block->sequence,
                'starts_at' => substr((string) $block->starts_at, 0, 5),
                'ends_at' => substr((string) $block->ends_at, 0, 5),
                'duration_minutes' => $this->durationMinutes((string) $block->starts_at, (string) $block->ends_at),
                'block_type' => (string) $block->block_type,
                'label' => (string) $block->label,
                'responsibility' => (string) $block->responsibility,
                'include_in_planning' => (bool) $block->include_in_planning,
                'field_codes' => array_values($block->field_codes ?? []),
                'notes' => $block->notes,
            ])->values()->all(),
        ];
    }

    /**
     * Expande el horario semanal del grupo al periodo exacto solicitado.
     *
     * @return array<string,mixed>
     */
    public function build(GroupSchedule $schedule, CarbonInterface|string $startsOn, CarbonInterface|string $endsOn): array
    {
        $schedule->loadMissing('blocks');

        $start = $startsOn instanceof CarbonInterface
            ? CarbonImmutable::instance($startsOn)->startOfDay()
            : CarbonImmutable::parse($startsOn)->startOfDay();
        $end = $endsOn instanceof CarbonInterface
            ? CarbonImmutable::instance($endsOn)->startOfDay()
            : CarbonImmutable::parse($endsOn)->startOfDay();

        $byDay = $schedule->blocks->groupBy(fn ($block) => (int) $block->day_of_week);
        $days = [];

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $weekday = (int) $date->isoWeekday();
            $blocks = $byDay->get($weekday, collect());
            if ($blocks->isEmpty()) {
                continue;
            }

            $serialized = [];
            $planeableMinutes = 0;

            foreach ($blocks as $block) {
                $duration = $this->durationMinutes((string) $block->starts_at, (string) $block->ends_at);
                $planeable = (bool) $block->include_in_planning
                    && ! in_array((string) $block->block_type, ['break', 'external'], true)
                    && (string) $block->responsibility !== 'external';

                if ($planeable) {
                    $planeableMinutes += $duration;
                }

                $serialized[] = [
                    'schedule_block_id' => (int) $block->id,
                    'sequence' => (int) $block->sequence,
                    'starts_at' => substr((string) $block->starts_at, 0, 5),
                    'ends_at' => substr((string) $block->ends_at, 0, 5),
                    'duration_minutes' => $duration,
                    'block_type' => (string) $block->block_type,
                    'label' => (string) $block->label,
                    'responsibility' => (string) $block->responsibility,
                    'include_in_planning' => $planeable,
                    'field_codes' => array_values($block->field_codes ?? []),
                    'notes' => $block->notes,
                ];
            }

            $days[] = [
                'date' => $date->format('Y-m-d'),
                'day_of_week' => $weekday,
                'planeable_minutes' => $planeableMinutes,
                'requires_planning' => $planeableMinutes > 0,
                'blocks' => $serialized,
            ];
        }

        return [
            'schema_version' => 1,
            'schedule_revision' => (int) $schedule->revision,
            'starts_on' => $start->format('Y-m-d'),
            'ends_on' => $end->format('Y-m-d'),
            'rules' => [
                'sessions_must_have_dates' => true,
                'sessions_must_use_calendar_dates' => true,
                'all_days_with_planeable_time_must_be_covered' => true,
                'daily_session_minutes_must_not_exceed_planeable_minutes' => true,
                'break_and_external_blocks_are_not_planeable' => true,
            ],
            'days' => $days,
        ];
    }

    private function durationMinutes(string $startsAt, string $endsAt): int
    {
        $start = CarbonImmutable::createFromFormat('H:i:s', strlen($startsAt) >= 8 ? substr($startsAt, 0, 8) : $startsAt . ':00');
        $end = CarbonImmutable::createFromFormat('H:i:s', strlen($endsAt) >= 8 ? substr($endsAt, 0, 8) : $endsAt . ':00');

        return (int) $start->diffInMinutes($end);
    }
}
