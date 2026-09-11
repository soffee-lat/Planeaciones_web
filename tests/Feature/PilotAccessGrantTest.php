<?php

namespace Tests\Feature;

use App\Actions\Planning\StartPlanningExperiment;
use App\Models\Plan;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use App\Models\Subscription;

class PilotAccessGrantTest extends PedagogyTestCase
{
    public function test_otorga_acceso_piloto_gratuito_vigente_e_idempotente(): void
    {
        $ctx = $this->seedFullTeacher();

        $this->artisan('validation:grant-pilot-access', [
            'email' => $ctx['user']->email,
            '--days' => 30,
        ])->expectsOutputToContain('Prompts IA listos')
            ->expectsOutputToContain('Acceso piloto otorgado')
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

        foreach (['planning.generation', 'planning.audit', 'planning.correction'] as $key) {
            $template = PromptTemplate::query()->where('key', $key)->firstOrFail();
            $this->assertNotNull($template->active_version_id);
            $this->assertNotNull($template->activeVersion?->published_at);
            $this->assertNotEmpty($template->activeVersion?->checksum);
        }

        $this->artisan('validation:grant-pilot-access', [
            'email' => $ctx['user']->email,
            '--days' => 30,
        ])->expectsOutputToContain('Prompts IA listos')
            ->expectsOutputToContain('Acceso ya vigente')
            ->assertSuccessful();

        $this->assertSame(1, Subscription::query()->where('customer_id', $ctx['user']->id)->count());
        $this->assertSame(1, $subscription->periods()->count());
        $this->assertSame(3, PromptTemplate::query()->count());
        $this->assertSame(3, PromptVersion::query()->count());
    }

    public function test_comando_de_prompts_es_idempotente_y_publica_los_tres_contratos(): void
    {
        $this->seedFullTeacher();

        $this->artisan('validation:ensure-ai-prompts')
            ->expectsOutputToContain('generation: template=planning.generation')
            ->expectsOutputToContain('audit: template=planning.audit')
            ->expectsOutputToContain('correction: template=planning.correction')
            ->assertSuccessful();

        $this->artisan('validation:ensure-ai-prompts')->assertSuccessful();

        $this->assertSame(3, PromptTemplate::query()->count());
        $this->assertSame(3, PromptVersion::query()->count());
        $this->assertSame(
            ['audit_result_v1', 'correction_result_v1', 'generated_plan_draft_v1'],
            PromptVersion::query()->orderBy('schema_version')->pluck('schema_version')->all(),
        );
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
