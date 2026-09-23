<?php

namespace Tests\Unit;

use App\Models\Curriculum;
use App\Models\EducationalPhase;
use App\Models\Grade;
use App\Services\Pedagogy\SupportedEducationalScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SupportedEducationalScopeTest extends TestCase
{
    private function grade(string $phase, int $ordinal): Grade
    {
        $grade = new Grade(['ordinal' => $ordinal]);
        $grade->setRelation('educationalPhase', new EducationalPhase(['code' => $phase]));

        return $grade;
    }

    public static function validCases(): array
    {
        return [
            ['preschool', 'F2', 1],
            ['preschool', 'F2', 2],
            ['preschool', 'F2', 3],
            ['primary', 'F3', 1],
            ['primary', 'F3', 2],
            ['primary', 'F4', 3],
            ['primary', 'F4', 4],
            ['primary', 'F5', 5],
            ['primary', 'F5', 6],
        ];
    }

    #[DataProvider('validCases')]
    public function test_only_preschool_f2_and_primary_f3_to_f5_are_supported(string $level, string $phase, int $ordinal): void
    {
        $result = (new SupportedEducationalScope())->assert(
            new Curriculum(['educational_level' => $level]),
            $this->grade($phase, $ordinal),
        );

        $this->assertSame($level, $result['level']->value);
        $this->assertSame($phase, $result['phase_code']);
        $this->assertSame($ordinal, $result['grade_ordinal']);
    }

    public static function invalidCases(): array
    {
        return [
            ['preschool', 'F1', 1],
            ['preschool', 'F3', 1],
            ['preschool', 'F2', 4],
            ['primary', 'F2', 1],
            ['primary', 'F3', 3],
            ['primary', 'F5', 4],
            ['secondary', 'F6', 1],
            ['initial', 'F1', 1],
        ];
    }

    #[DataProvider('invalidCases')]
    public function test_initial_secondary_and_invalid_phase_grade_pairs_are_rejected(string $level, string $phase, int $ordinal): void
    {
        $this->expectException(RuntimeException::class);

        (new SupportedEducationalScope())->assert(
            new Curriculum(['educational_level' => $level]),
            $this->grade($phase, $ordinal),
        );
    }
}
