<?php

namespace App\Actions\Planning;

use App\Models\PlanVersion;
use App\Models\PlanningRequest;
use App\Services\Planning\PlanningUnitCalculator;

class CalculatePlanningCommercialRequirements
{
    public function __construct(private PlanningUnitCalculator $calculator) {}

    /** @return array<string, mixed> */
    public function execute(PlanningRequest $request, PlanVersion $version): array
    {
        return $this->forDates($request->starts_on->toDateString(), $request->ends_on->toDateString(), $version);
    }

    /** Pure preview: no writes. @return array<string, mixed> */
    public function forDates(string $startsOn, string $endsOn, PlanVersion $version): array
    {
        $calculation = $this->calculator->calculate($startsOn, $endsOn, (int) $version->max_planning_days);
        $calculation['segments'] = array_map(fn (array $segment): array => [
            'sequence' => $segment['index'], 'starts_on' => $segment['starts_on'],
            'ends_on' => $segment['ends_on'], 'calendar_days' => $segment['days'], 'units' => 1,
        ], $calculation['segments']);

        return $calculation;
    }
}
