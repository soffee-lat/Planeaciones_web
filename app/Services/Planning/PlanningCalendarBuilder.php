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
            'active_days' => array_values(array_map('intval', $schedule->active_days ?? [])),
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
        $activeDays = array_values(array_unique(array_map('intval', $schedule->active_days ?? [])));
        $dayStart = substr((string) $schedule->day_starts_at, 0, 5);
        $dayEnd = substr((string) $schedule->day_ends_at, 0, 5);
        $days = [];

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $weekday = (int) $date->isoWeekday();
            if (! in_array($weekday, $activeDays, true)) {
                continue;
            }

            $blocks = $byDay->get($weekday, collect())->sortBy('starts_at')->values();
            $serialized = [];
            $planeableMinutes = 0;
            $cursor = $dayStart;
            $sequence = 1;

            foreach ($blocks as $block) {
                $blockStart = substr((string) $block->starts_at, 0, 5);
                $blockEnd = substr((string) $block->ends_at, 0, 5);

                if ($this->timeMinutes($blockStart) > $this->timeMinutes($cursor)) {
                    $gapDuration = $this->durationMinutes($cursor, $blockStart);
                    $serialized[] = [
                        'schedule_block_id' => null,
                        'sequence' => $sequence++,
                        'starts_at' => $cursor,
                        'ends_at' => $blockStart,
                        'duration_minutes' => $gapDuration,
                        'block_type' => 'flexible',
                        'label' => 'Tiempo disponible',
                        'responsibility' => 'main_teacher',
                        'include_in_planning' => true,
                        'field_codes' => [],
                        'notes' => null,
                    ];
                    $planeableMinutes += $gapDuration;
                }

                $duration = $this->durationMinutes($blockStart, $blockEnd);
                $planeable = (bool) $block->include_in_planning
                    && ! in_array((string) $block->block_type, ['break', 'external'], true)
                    && (string) $block->responsibility !== 'external';

                if ($planeable) {
                    $planeableMinutes += $duration;
                }

                $serialized[] = [
                    'schedule_block_id' => (int) $block->id,
                    'sequence' => $sequence++,
                    'starts_at' => $blockStart,
                    'ends_at' => $blockEnd,
                    'duration_minutes' => $duration,
                    'block_type' => (string) $block->block_type,
                    'label' => (string) $block->label,
                    'responsibility' => (string) $block->responsibility,
                    'include_in_planning' => $planeable,
                    'field_codes' => array_values($block->field_codes ?? []),
                    'notes' => $block->notes,
                ];

                $cursor = $blockEnd;
            }

            if ($this->timeMinutes($cursor) < $this->timeMinutes($dayEnd)) {
                $gapDuration = $this->durationMinutes($cursor, $dayEnd);
                $serialized[] = [
                    'schedule_block_id' => null,
                    'sequence' => $sequence,
                    'starts_at' => $cursor,
                    'ends_at' => $dayEnd,
                    'duration_minutes' => $gapDuration,
                    'block_type' => 'flexible',
                    'label' => 'Tiempo disponible',
                    'responsibility' => 'main_teacher',
                    'include_in_planning' => true,
                    'field_codes' => [],
                    'notes' => null,
                ];
                $planeableMinutes += $gapDuration;
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

    private function timeMinutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return ($hour * 60) + $minute;
    }

    private function durationMinutes(string $startsAt, string $endsAt): int
    {
        $start = CarbonImmutable::createFromFormat('H:i:s', strlen($startsAt) >= 8 ? substr($startsAt, 0, 8) : $startsAt . ':00');
        $end = CarbonImmutable::createFromFormat('H:i:s', strlen($endsAt) >= 8 ? substr($endsAt, 0, 8) : $endsAt . ':00');

        return (int) $start->diffInMinutes($end);
    }
}
