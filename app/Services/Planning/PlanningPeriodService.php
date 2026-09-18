<?php

namespace App\Services\Planning;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class PlanningPeriodService
{
    /**
     * @return array{period_type:string,period_key:string,label:string,starts_on:string,ends_on:string,weeks:list<array{sequence:int,starts_on:string,ends_on:string,label:string}>}
     */
    public function resolve(string $periodType, string $periodKey): array
    {
        return match ($periodType) {
            'week' => $this->resolveWeek($periodKey),
            'month' => $this->resolveMonth($periodKey),
            default => throw new InvalidArgumentException('PLANNING_PERIOD_TYPE_INVALID'),
        };
    }

    /**
     * Semanas disponibles para un ciclo escolar. La clave siempre es el lunes
     * de la semana y el rango escolar es lunes-viernes.
     *
     * @return list<array{key:string,starts_on:string,ends_on:string,label:string}>
     */
    public function cycleWeeks(string $schoolYear): array
    {
        [$firstYear, $secondYear] = $this->schoolYears($schoolYear);
        $start = CarbonImmutable::create($firstYear, 8, 1)->nextOrSame(CarbonInterface::MONDAY);
        $end = CarbonImmutable::create($secondYear, 7, 31)->endOfDay();

        $weeks = [];
        for ($monday = $start; $monday->lte($end); $monday = $monday->addWeek()) {
            $friday = $monday->addDays(4);
            if ($monday->year > $secondYear || $monday->gt($end)) {
                break;
            }
            $weeks[] = [
                'key' => $monday->toDateString(),
                'starts_on' => $monday->toDateString(),
                'ends_on' => $friday->toDateString(),
                'label' => $this->weekLabel($monday, $friday),
            ];
        }

        return $weeks;
    }

    /**
     * @return list<array{key:string,starts_on:string,ends_on:string,label:string}>
     */
    public function cycleMonths(string $schoolYear): array
    {
        [$firstYear, $secondYear] = $this->schoolYears($schoolYear);
        $cursor = CarbonImmutable::create($firstYear, 8, 1);
        $end = CarbonImmutable::create($secondYear, 7, 1);
        $months = [];

        while ($cursor->lte($end)) {
            $resolved = $this->resolveMonth($cursor->format('Y-m'));
            $months[] = [
                'key' => $cursor->format('Y-m'),
                'starts_on' => $resolved['starts_on'],
                'ends_on' => $resolved['ends_on'],
                'label' => $this->monthName($cursor->month) . ' ' . $cursor->year,
            ];
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    /** @return array{int,int} */
    private function schoolYears(string $schoolYear): array
    {
        if (preg_match('/(20\d{2}).*?(20\d{2})/', $schoolYear, $matches) === 1) {
            $first = (int) $matches[1];
            $second = (int) $matches[2];
            if ($second >= $first) {
                return [$first, $second];
            }
        }

        $now = CarbonImmutable::now();
        $first = $now->month >= 8 ? $now->year : $now->year - 1;

        return [$first, $first + 1];
    }

    /** @return array{period_type:string,period_key:string,label:string,starts_on:string,ends_on:string,weeks:list<array{sequence:int,starts_on:string,ends_on:string,label:string}>} */
    private function resolveWeek(string $periodKey): array
    {
        try {
            $monday = CarbonImmutable::parse($periodKey)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException('PLANNING_PERIOD_KEY_INVALID');
        }

        if ($monday->isoWeekday() !== CarbonInterface::MONDAY) {
            throw new InvalidArgumentException('PLANNING_PERIOD_WEEK_MUST_START_MONDAY');
        }

        $friday = $monday->addDays(4);

        return [
            'period_type' => 'week',
            'period_key' => $monday->toDateString(),
            'label' => $this->weekLabel($monday, $friday),
            'starts_on' => $monday->toDateString(),
            'ends_on' => $friday->toDateString(),
            'weeks' => [[
                'sequence' => 1,
                'starts_on' => $monday->toDateString(),
                'ends_on' => $friday->toDateString(),
                'label' => $this->weekLabel($monday, $friday),
            ]],
        ];
    }

    /** @return array{period_type:string,period_key:string,label:string,starts_on:string,ends_on:string,weeks:list<array{sequence:int,starts_on:string,ends_on:string,label:string}>} */
    private function resolveMonth(string $periodKey): array
    {
        if (preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/', $periodKey) !== 1) {
            throw new InvalidArgumentException('PLANNING_PERIOD_KEY_INVALID');
        }

        $monthStart = CarbonImmutable::parse($periodKey . '-01')->startOfDay();
        $monthEnd = $monthStart->endOfMonth()->startOfDay();
        $weeks = [];

        $cursor = $monthStart;
        while ($cursor->isWeekend()) {
            $cursor = $cursor->addDay();
        }

        $sequence = 1;
        while ($cursor->lte($monthEnd)) {
            $monday = $cursor->startOfWeek(CarbonInterface::MONDAY);
            $friday = $monday->addDays(4);

            $segmentStart = $cursor->gt($monday) ? $cursor : $monday;
            if ($segmentStart->lt($monthStart)) {
                $segmentStart = $monthStart;
            }
            while ($segmentStart->isWeekend() && $segmentStart->lte($monthEnd)) {
                $segmentStart = $segmentStart->addDay();
            }

            $segmentEnd = $friday->lt($monthEnd) ? $friday : $monthEnd;
            while ($segmentEnd->isWeekend() && $segmentEnd->gte($segmentStart)) {
                $segmentEnd = $segmentEnd->subDay();
            }

            if ($segmentStart->lte($segmentEnd) && $segmentStart->month === $monthStart->month) {
                $weeks[] = [
                    'sequence' => $sequence++,
                    'starts_on' => $segmentStart->toDateString(),
                    'ends_on' => $segmentEnd->toDateString(),
                    'label' => $this->weekLabel($segmentStart, $segmentEnd),
                ];
            }

            $cursor = $monday->addWeek();
            if ($cursor->gt($monthEnd)) {
                break;
            }
        }

        if ($weeks === []) {
            throw new InvalidArgumentException('PLANNING_PERIOD_MONTH_WITHOUT_SCHOOL_DAYS');
        }

        return [
            'period_type' => 'month',
            'period_key' => $periodKey,
            'label' => $this->monthName($monthStart->month) . ' ' . $monthStart->year,
            'starts_on' => $monthStart->toDateString(),
            'ends_on' => $monthEnd->toDateString(),
            'weeks' => $weeks,
        ];
    }

    private function weekLabel(CarbonImmutable $start, CarbonImmutable $end): string
    {
        if ($start->month === $end->month) {
            return $start->day . '–' . $end->day . ' ' . $this->monthShort($start->month) . ' ' . $end->year;
        }

        return $start->day . ' ' . $this->monthShort($start->month)
            . ' – ' . $end->day . ' ' . $this->monthShort($end->month)
            . ' ' . $end->year;
    }

    private function monthShort(int $month): string
    {
        return [
            1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr',
            5 => 'may', 6 => 'jun', 7 => 'jul', 8 => 'ago',
            9 => 'sep', 10 => 'oct', 11 => 'nov', 12 => 'dic',
        ][$month];
    }

    private function monthName(int $month): string
    {
        return [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ][$month];
    }
}
