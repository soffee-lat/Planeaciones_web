<?php

namespace Tests\Unit;

use App\Models\Curriculum;
use App\Models\Grade;
use App\Services\Pedagogy\PedagogicalStageProfile;
use PHPUnit\Framework\TestCase;

class PedagogicalStageProfileTest extends TestCase
{
    public function test_progression_keeps_preschool_three_immediately_before_primary_one(): void
    {
        $service = new PedagogicalStageProfile();

        $preschool = $service->for(
            new Curriculum(['educational_level' => 'preschool']),
            new Grade(['code' => 'P3', 'name' => 'Tercer grado de preescolar', 'ordinal' => 3]),
        );
        $primary = $service->for(
            new Curriculum(['educational_level' => 'primary']),
            new Grade(['code' => 'G1', 'name' => 'Primer grado', 'ordinal' => 1]),
        );

        $this->assertSame('preschool_3', $preschool['profile_key']);
        $this->assertSame(3, $preschool['complexity_band']);
        $this->assertSame('primary_1', $primary['profile_key']);
        $this->assertSame(4, $primary['complexity_band']);
        $this->assertSame($preschool['complexity_band'] + 1, $primary['complexity_band']);
    }

    public function test_primary_fifth_is_more_demanding_than_primary_first_but_still_child_focused(): void
    {
        $service = new PedagogicalStageProfile();
        $curriculum = new Curriculum(['educational_level' => 'primary']);

        $first = $service->for(
            $curriculum,
            new Grade(['code' => 'G1', 'name' => 'Primer grado', 'ordinal' => 1]),
        );
        $fifth = $service->for(
            $curriculum,
            new Grade(['code' => 'G5', 'name' => 'Quinto grado', 'ordinal' => 5]),
        );

        $this->assertGreaterThan($first['complexity_band'], $fifth['complexity_band']);
        $this->assertStringContainsString(
            'niñas y niños',
            implode(' ', $fifth['planning_guardrails']),
        );
        $this->assertStringContainsString('no escala oficial', $fifth['complexity_note']);
    }

    public function test_preschool_profiles_keep_play_and_avoid_forced_conventional_literacy(): void
    {
        $profile = (new PedagogicalStageProfile())->for(
            new Curriculum(['educational_level' => 'preschool']),
            new Grade(['code' => 'P1', 'name' => 'Primer grado de preescolar', 'ordinal' => 1]),
        );

        $this->assertStringContainsString('juego', mb_strtolower(implode(' ', $profile['planning_guardrails'])));
        $this->assertStringContainsString('lectura convencional', mb_strtolower(implode(' ', $profile['avoid'])));
    }
}
