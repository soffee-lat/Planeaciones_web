<?php

namespace Tests\Feature\AI;

use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\AI\RouteAuditResult;
use App\Enums\AiExecutionStage;
use App\Enums\OutboxEventType;
use App\Enums\PlanningRequestStatus;
use App\Models\AiExecution;
use App\Models\Approval;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Feature\PedagogyTestCase;

class PlanningCorrectionIntegrityTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;

    public function refreshDatabase(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
        $this->beforeApplicationDestroyed(function (): void {
            Artisan::call('migrate:rollback', ['--force' => true]);
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_bd_rechaza_correccion_directa_sin_execution_y_outbox(): void
    {
        $scene = $this->succeededAuditScenario(false);
        $statementsCompleted = false;

        try {
            DB::transaction(function () use ($scene, &$statementsCompleted): void {
                DB::table('planning_requests')->where('id', $scene['request']->id)->update([
                    'status' => PlanningRequestStatus::CORRECCION_IA->value,
                ]);
                $statementsCompleted = true;
            });
            $this->fail('CORRECCION_IA requiere ejecución y outbox coherentes.');
        } catch (\PDOException $e) {
            $this->assertTrue($statementsCompleted);
            $this->assertStringContainsString('AI_CORRECTION_EXECUTION_REQUIRED', $e->getMessage());
        }

        $this->assertSame(PlanningRequestStatus::AUDITORIA_IA, $scene['request']->fresh()->status);
    }

    public function test_bd_rechaza_correccion_si_auditoria_paso(): void
    {
        $scene = $this->succeededAuditScenario(true);

        try {
            DB::transaction(function () use ($scene): void {
                DB::table('planning_requests')->where('id', $scene['request']->id)->update([
                    'status' => PlanningRequestStatus::CORRECCION_IA->value,
                ]);
            });
            $this->fail('Una auditoría aprobada no debe entrar a corrección interna.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('AI_CORRECTION_REQUIRES_FAILED_AUDIT', $e->getMessage());
        }
    }

    public function test_bd_rechaza_aprobada_sin_aprobacion_ai_exacta(): void
    {
        $scene = $this->succeededAuditScenario(true);

        try {
            DB::transaction(function () use ($scene): void {
                DB::table('planning_requests')->where('id', $scene['request']->id)->update([
                    'status' => PlanningRequestStatus::APROBADA->value,
                ]);
            });
            $this->fail('APROBADA requiere Approval AI sobre la versión exacta.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('AI_APPROVAL_REQUIRED', $e->getMessage());
        }
    }

    public function test_bd_rechaza_aprobacion_ai_fabricada_desde_auditoria_fallida(): void
    {
        $scene = $this->succeededAuditScenario(false);

        try {
            DB::transaction(function () use ($scene): void {
                DB::table('approvals')->insert([
                    'request_id' => $scene['request']->id,
                    'version_id' => $scene['version']->id,
                    'kind' => 'ai',
                    'ai_execution_id' => $scene['audit']->id,
                    'review_id' => null,
                    'actor_id' => null,
                    'approved_at' => now(),
                    'created_at' => now(),
                ]);
            });
            $this->fail('Audit failed no puede respaldar Approval AI.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('AI_APPROVAL_AUDIT_MISMATCH', $e->getMessage());
        }
    }

    public function test_bd_rechaza_aprobacion_humana_con_revision_inexistente(): void
    {
        $scene = $this->succeededAuditScenario(true, [
            'human_review_required' => true,
            'human_review_limit' => 8,
        ]);

        try {
            DB::transaction(function () use ($scene): void {
                DB::table('approvals')->insert([
                    'request_id' => $scene['request']->id,
                    'version_id' => $scene['version']->id,
                    'kind' => 'human',
                    'ai_execution_id' => null,
                    'review_id' => 123456,
                    'actor_id' => null,
                    'approved_at' => now(),
                    'created_at' => now(),
                ]);
            });
            $this->fail('Una Approval humana no puede referenciar una review inexistente.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('approvals_review_fk', $e->getMessage());
        }
    }

    public function test_bd_exige_evento_de_estado_para_aprobacion_ai(): void
    {
        $scene = $this->succeededAuditScenario(true);

        try {
            DB::transaction(function () use ($scene): void {
                DB::table('approvals')->insert([
                    'request_id' => $scene['request']->id,
                    'version_id' => $scene['version']->id,
                    'kind' => 'ai',
                    'ai_execution_id' => $scene['audit']->id,
                    'review_id' => null,
                    'actor_id' => null,
                    'approved_at' => now(),
                    'created_at' => now(),
                ]);
                DB::table('planning_requests')->where('id', $scene['request']->id)->update([
                    'status' => PlanningRequestStatus::APROBADA->value,
                ]);
            });
            $this->fail('No debe existir APROBADA sin RequestStateEvent de ruteo.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('AI_APPROVAL_STATE_EVENT_REQUIRED', $e->getMessage());
        }
    }

    public function test_ruteo_normal_y_paquete_correction_satisfacen_guardas_diferidas(): void
    {
        $scene = $this->succeededAuditScenario(false);
        $request = app(RouteAuditResult::class)->execute(
            $scene['audit'],
            null,
            '81818181-8181-4818-8818-818181818181',
        );
        $correction = AiExecution::query()
            ->where('request_id', $request->id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->sole();
        $event = OutboxEvent::query()
            ->where('type', OutboxEventType::PlanningCorrectionRequested->value)
            ->where('aggregate_id', $request->id)
            ->sole();

        $this->assertTrue(app(ProcessOutboxEvent::class)->execute($event));
        $this->assertSame(PlanningRequestStatus::CORRECCION_IA, $request->fresh()->status);
        $this->assertSame('waiting_manual', $correction->fresh()->status->value);
        $this->assertDatabaseHas('ai_manual_packages', ['ai_execution_id' => $correction->id]);
    }
}
