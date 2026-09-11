<?php

namespace Tests\Feature;

use App\Actions\Planning\StartPlanningExperiment;
use App\Models\Plan;
use App\Models\Subscription;

class PilotAccessGrantTest extends PedagogyTestCase
{
    public function test_otorga_acceso_piloto_gratuito_vigente_e_idempotente(): void
    {
        $ctx = $this->seedFullTeacher();

        $this->artisan('validation:grant-pilot-access', [
            'email' => $ctx['user']->email,
            '--days' => 30,
        ])->expectsOutputToContain('Acceso piloto otorgado')
            ->assertSuccessful();

        $subscription = Subscription::query()->where('customer_id', $ctx['user']->id)->sole();
        $period = $subscription->currentPeriod();
        $plan = Plan::query()->findOrFail($subscription->plan_id);
        $version = $subscription->planVersion;

        $this->assertNotNull($period);
        $this->assertSame('validation-pilot', $plan->code);
        $this->assertSame(0, (int) $version->price_minor);
        $this->assertFalse((bool) $version->human_review_required);
        $this->assertTrue((bool) ($version->features['curricular_validation_pilot'] ?? false));
        $this->assertSame(50, (int) $version->planning_limit);

        $this->artisan('validation:grant-pilot-access', [
            'email' => $ctx['user']->email,
            '--days' => 30,
        ])->expectsOutputToContain('Acceso ya vigente')
            ->assertSuccessful();

        $this->assertSame(1, Subscription::query()->where('customer_id', $ctx['user']->id)->count());
        $this->assertSame(1, $subscription->periods()->count());
    }

    public function test_dispatch_generation_de_borrador_reporta_not_ready_en_vez_de_error_generico(): void
    {
        $ctx = $this->seedFullTeacher();
        $ctx['profile']->update([
            'student_count' => 25,
            'general_level' => 'medio',
            'session_minutes' => 50,
        ]);

        $request = app(StartPlanningExperiment::class)->execute(
            $ctx['user'],
            $ctx['group']->id,
            '2026-09-28',
            '2026-10-02',
            'Conociendo mi cuerpo',
        );

        $this->artisan('ai:dispatch-generation', ['request_id' => $request->id])
            ->expectsOutput('AI_GENERATION_REQUEST_NOT_READY')
            ->assertFailed();
    }
}
