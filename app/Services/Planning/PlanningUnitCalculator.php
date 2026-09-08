<?php

namespace App\Services\Planning;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Regla comercial oficial del MVP: `calendar_days_v1`.
 *
 *   D = días naturales inclusivos entre starts_on y ends_on (mismo día = 1).
 *   M = PlanVersion.max_planning_days.
 *   U = ceil(D / M).
 *
 * Los días son **naturales**: incluyen sábados, domingos y festivos. No hay
 * calendario escolar en el MVP; el POST-MVP podrá añadir otra estrategia
 * versionada (p. ej. `school_days_v2`).
 */
class PlanningUnitCalculator
{
    public const STRATEGY = 'calendar_days_v1';

    /**
     * @return array{
     *     strategy: string,
     *     starts_on: string,
     *     ends_on: string,
     *     max_planning_days: int,
     *     planning_days: int,
     *     planning_units: int,
     *     segments: list<array{index:int,starts_on:string,ends_on:string,days:int}>
     * }
     */
    public function calculate(string|CarbonImmutable $startsOn, string|CarbonImmutable $endsOn, int $maxPlanningDays): array
    {
        if ($maxPlanningDays <= 0) {
            throw new InvalidArgumentException('PLAN_VERSION_INVALID_MAX_PLANNING_DAYS');
        }

        $start = $startsOn instanceof CarbonImmutable ? $startsOn : CarbonImmutable::parse($startsOn);
        $end = $endsOn instanceof CarbonImmutable ? $endsOn : CarbonImmutable::parse($endsOn);
        $start = $start->startOfDay();
        $end = $end->startOfDay();

        if ($end->lt($start)) {
            throw new InvalidArgumentException('PLANNING_DATE_RANGE_INVALID');
        }

        // Días naturales inclusivos: mismo día = 1.
        $planningDays = (int) $start->diffInDays($end) + 1;
        $planningUnits = (int) ceil($planningDays / $maxPlanningDays);

        $segments = [];
        $cursor = $start;
        $index = 1;
        while ($cursor->lte($end)) {
            $segmentEnd = $cursor->addDays($maxPlanningDays - 1);
            if ($segmentEnd->gt($end)) {
                $segmentEnd = $end;
            }
            $segments[] = [
                'index' => $index,
                'starts_on' => $cursor->toDateString(),
                'ends_on' => $segmentEnd->toDateString(),
                'days' => (int) $cursor->diffInDays($segmentEnd) + 1,
            ];
            $cursor = $segmentEnd->addDay();
            $index++;
        }

        return [
            'strategy' => self::STRATEGY,
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
            'max_planning_days' => $maxPlanningDays,
            'planning_days' => $planningDays,
            'planning_units' => $planningUnits,
            'segments' => $segments,
        ];
    }
}
