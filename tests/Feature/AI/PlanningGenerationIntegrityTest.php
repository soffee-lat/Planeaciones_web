<?php

namespace Tests\Feature\AI;

use App\Actions\AI\DispatchPlanningGeneration;
use App\Actions\AI\PublishPromptVersion;
use App\Enums\PlanningRequestStatus;
use App\Enums\PromptCategory;
use App\Enums\UsageReservationStatus;
use App\Enums\UsageResource;
use App\Models\AiExecution;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use App\Models\RequestStateEvent;
use App\Models\UsageReservation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Feature\PedagogyTestCase;

class PlanningGenerationIntegrityTest extends PedagogyTestCase
{
    use CreatesCommercialPlanningScenario;

    /** Ejecuta commits reales para disparar constraint triggers diferidos. */
    public function refreshDatabase(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
        $this->beforeApplicationDestroyed(function (): void {
            Artisan::call('migrate:rollback', ['--force' => true]);
            RefreshDatabaseState::$migrated = false;
        });
    }

    private function readyRequest(): PlanningRequest
    {
        $request = $this->draft();
        $this->period($request);

        return $this->authorize($this->confirm($request));
    }

    private function publishPrompt(): PromptVersion
    {
        $template = PromptTemplate::factory()->create([
            'key' => 'planning.generation',
            'category' => PromptCategory::Generation->value,
        ]);
        $version = PromptVersion::factory()->create([
            'template_id' => $template->id,
            'body' => 'INPUT={{input_snapshot}} OUTPUT={{output_schema}}',
            'allowed_variables' => ['input_snapshot', 'output_schema'],
        ]);

        return app(PublishPromptVersion::class)->execute($this->admin(), $version);
    }

    public function test_bd_rechaza_salto_directo_a_generacion_sin_consumo_execution_evento_y_outbox(): void
    {
        $request = $this->readyRequest();
        $statementsCompleted = false;

        try {
            DB::transaction(function () use ($request, &$statementsCompleted): void {
                DB::table('planning_requests')->where('id', $request->id)->update([
                    'status' => PlanningRequestStatus::GENERACION_IA->value,
                ]);
                $statementsCompleted = true;
            });
            $this->fail('El estado GENERACION_IA no puede materializarse sin el arranque transaccional.');
        } catch (\PDOException $error) {
            $this->assertTrue($statementsCompleted, 'El rechazo debe ocurrir al COMMIT por el trigger diferido.');
            $this->assertStringContainsString('AI_GENERATION_PLANNING_CONSUMPTION_REQUIRED', $error->getMessage());
        }

        $this->assertSame(PlanningRequestStatus::LISTA_PARA_PROCESAR, $request->fresh()->status);
        $this->assertSame(UsageReservationStatus::Reserved, UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::Planning->value)
            ->sole()->status);
        $this->assertDatabaseCount('ai_executions', 0);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_dispatch_normal_satisface_invariante_diferida_en_commit_real(): void
    {
        config(['ai.mode' => 'manual']);
        $request = $this->readyRequest();
        $this->publishPrompt();

        $execution = app(DispatchPlanningGeneration::class)->execute(
            $request,
            '55555555-5555-4555-8555-555555555555',
        );

        $request = $request->fresh();
        $this->assertSame(PlanningRequestStatus::GENERACION_IA, $request->status);
        $this->assertSame(UsageReservationStatus::Consumed, UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::Planning->value)
            ->sole()->status);
        $this->assertSame(1, AiExecution::query()->where('request_id', $request->id)->count());
        $this->assertSame(1, OutboxEvent::query()->where('aggregate_id', $request->id)->count());
        $this->assertSame(1, RequestStateEvent::query()
            ->where('request_id', $request->id)
            ->where('from_status', PlanningRequestStatus::LISTA_PARA_PROCESAR->value)
            ->where('to_status', PlanningRequestStatus::GENERACION_IA->value)
            ->count());
        $this->assertSame($execution->id, (int) OutboxEvent::query()->sole()->payload['ai_execution_id']);
    }

    public function test_estado_posterior_no_rompe_guard_si_arranque_de_generacion_ya_es_valido(): void
    {
        config(['ai.mode' => 'manual']);
        $request = $this->readyRequest();
        $this->publishPrompt();
        app(DispatchPlanningGeneration::class)->execute($request);

        DB::transaction(function () use ($request): void {
            DB::table('planning_requests')->where('id', $request->id)->update([
                'status' => PlanningRequestStatus::AUDITORIA_IA->value,
            ]);
        });

        $this->assertSame(PlanningRequestStatus::AUDITORIA_IA, $request->fresh()->status);
        $this->assertSame(UsageReservationStatus::Consumed, UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::Planning->value)
            ->sole()->status);
    }
}
