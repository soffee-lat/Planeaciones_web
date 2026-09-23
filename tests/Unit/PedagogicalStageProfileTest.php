<?php

namespace Tests\Unit;

use App\Models\Curriculum;
use App\Models\EducationalPhase;
use App\Models\Grade;
use App\Services\Pedagogy\PedagogicalStageProfile;
use App\Services\Pedagogy\SupportedEducationalScope;
use PHPUnit\Framework\TestCase;

class PedagogicalStageProfileTest extends TestCase
{
    private function profile(): PedagogicalStageProfile
    {
        return new PedagogicalStageProfile(new SupportedEducationalScope());
    }

    private function grade(string $phase, string $code, string $name, int $ordinal): Grade
    {
        $grade = new Grade(['code' => $code, 'name' => $name, 'ordinal' => $ordinal]);
        $grade->setRelation('educationalPhase', new EducationalPhase(['code' => $phase, 'name' => 'Fase ' . substr($phase, 1)]));

        return $grade;
    }

    public function test_progression_keeps_preschool_three_immediately_before_primary_one(): void
    {
        $service = $this->profile();

        $preschool = $service->for(
            new Curriculum(['educational_level' => 'preschool']),
            $this->grade('F2', 'P3', 'Tercer grado de preescolar', 3),
        );
        $primary = $service->for(
            new Curriculum(['educational_level' => 'primary']),
            $this->grade('F3', 'G1', 'Primer grado', 1),
        );

        $this->assertSame('preschool_3', $preschool['profile_key']);
        $this->assertSame(3, $preschool['complexity_band']);
        $this->assertSame('primary_1', $primary['profile_key']);
        $this->assertSame(4, $primary['complexity_band']);
        $this->assertSame($preschool['complexity_band'] + 1, $primary['complexity_band']);
    }

    public function test_primary_fifth_is_more_demanding_than_primary_first_but_still_child_focused(): void
    {
        $service = $this->profile();
        $curriculum = new Curriculum(['educational_level' => 'primary']);

        $first = $service->for(
            $curriculum,
            $this->grade('F3', 'G1', 'Primer grado', 1),
        );
        $fifth = $service->for(
            $curriculum,
            $this->grade('F5', 'G5', 'Quinto grado', 5),
        );

        $this->assertGreaterThan($first['complexity_band'], $fifth['complexity_band']);
        $this->assertStringContainsString('niñas y niños', implode(' ', $fifth['planning_guardrails']));
        $this->assertStringContainsString('no escala oficial', $fifth['complexity_note']);
    }

    public function test_preschool_profiles_keep_play_and_avoid_forced_conventional_literacy(): void
    {
        $profile = $this->profile()->for(
            new Curriculum(['educational_level' => 'preschool']),
            $this->grade('F2', 'P1', 'Primer grado de preescolar', 1),
        );

        $this->assertStringContainsString('juego', mb_strtolower(implode(' ', $profile['planning_guardrails'])));
        $this->assertStringContainsString('lectura convencional', mb_strtolower(implode(' ', $profile['avoid'])));
    }
}
