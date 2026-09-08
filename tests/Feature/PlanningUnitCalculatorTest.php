<?php

namespace Tests\Feature;

use App\Services\Planning\PlanningUnitCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlanningUnitCalculatorTest extends TestCase
{
    public static function unitsProvider(): array
    {
        // [start, end, M, expected_days, expected_units]
        return [
            'mismo dia'  => ['2026-01-01', '2026-01-01', 7, 1, 1],
            '7 dias'     => ['2026-01-01', '2026-01-07', 7, 7, 1],
            '8 dias'     => ['2026-01-01', '2026-01-08', 7, 8, 2],
            '14 dias'    => ['2026-01-01', '2026-01-14', 7, 14, 2],
            '15 dias'    => ['2026-01-01', '2026-01-15', 7, 15, 3],
            '28 dias'    => ['2026-01-01', '2026-01-28', 7, 28, 4],
            '31 dias'    => ['2026-01-01', '2026-01-31', 7, 31, 5],
            'fin semana' => ['2026-01-03', '2026-01-04', 7, 2, 1], // sábado-domingo cuentan
        ];
    }

    #[Test]
    #[DataProvider('unitsProvider')]
    public function calcula_unidades_calendar_days_v1(string $start, string $end, int $m, int $days, int $units): void
    {
        $result = (new PlanningUnitCalculator())->calculate($start, $end, $m);
        $this->assertSame('calendar_days_v1', $result['strategy']);
        $this->assertSame($days, $result['planning_days']);
        $this->assertSame($units, $result['planning_units']);
        $this->assertCount($units, $result['segments']);
    }

    #[Test]
    public function segmentacion_18_dias_produce_tres_segmentos_sin_solape_ni_hueco(): void
    {
        $result = (new PlanningUnitCalculator())->calculate('2026-01-01', '2026-01-18', 7);
        $this->assertSame(18, $result['planning_days']);
        $this->assertSame(3, $result['planning_units']);
        $this->assertSame([
            ['index' => 1, 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-07', 'days' => 7],
            ['index' => 2, 'starts_on' => '2026-01-08', 'ends_on' => '2026-01-14', 'days' => 7],
            ['index' => 3, 'starts_on' => '2026-01-15', 'ends_on' => '2026-01-18', 'days' => 4],
        ], $result['segments']);
    }

    #[Test]
    public function rechaza_rango_invertido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PLANNING_DATE_RANGE_INVALID');
        (new PlanningUnitCalculator())->calculate('2026-01-10', '2026-01-01', 7);
    }

    #[Test]
    public function rechaza_max_planning_days_no_positivo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PLAN_VERSION_INVALID_MAX_PLANNING_DAYS');
        (new PlanningUnitCalculator())->calculate('2026-01-01', '2026-01-07', 0);
    }
}
