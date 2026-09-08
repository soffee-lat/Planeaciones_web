<?php

namespace Tests\Feature;

use App\Enums\SchoolType;
use App\Models\School;
use App\Services\Onboarding\OnboardingProgress;

class OnboardingPedagogyTest extends PedagogyTestCase
{
    public function test_onboarding_incompleto_sin_escuela(): void
    {
        $user = $this->customer();
        $this->assertFalse(OnboardingProgress::isPedagogicalComplete($user));
        $this->assertFalse($user->hasPedagogicalOnboardingComplete());
    }

    public function test_onboarding_incompleto_sin_grupo(): void
    {
        $user = $this->customer();
        School::factory()->create(['owner_id' => $user->id, 'school_type' => SchoolType::Public->value]);
        $this->assertFalse(OnboardingProgress::isPedagogicalComplete($user));
    }

    public function test_onboarding_completo_con_escuela_grupo_y_perfil(): void
    {
        $seed = $this->seedFullTeacher();
        $this->assertTrue(OnboardingProgress::isPedagogicalComplete($seed['user']));
        $this->assertTrue($seed['user']->hasPedagogicalOnboardingComplete());
    }

    public function test_grupo_archivado_no_cuenta_como_completo(): void
    {
        $seed = $this->seedFullTeacher();
        $seed['group']->forceFill(['archived_at' => now()])->save();
        $this->assertFalse(OnboardingProgress::isPedagogicalComplete($seed['user']));
    }

    public function test_cuenta_ajena_no_influye_en_progreso(): void
    {
        $solo = $this->customer();
        $this->seedFullTeacher(); // otra cuenta completa
        $this->assertFalse(OnboardingProgress::isPedagogicalComplete($solo));
    }
}
