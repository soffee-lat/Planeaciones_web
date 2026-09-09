<?php

namespace Tests\Feature\AI;

use App\Actions\AI\ImportManualAuditResult;
use App\Actions\AI\ImportManualCorrectionResult;
use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\AI\RouteAuditResult;
use App\Enums\AiExecutionStage;
use App\Enums\ApprovalKind;
use App\Enums\OutboxEventType;
use App\Enums\PlanningRequestStatus;
use App\Enums\UsageReservationStatus;
use App\Enums\UsageResource;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use App\Models\Approval;
use App\Models\OutboxEvent;
use App\Models\RequestBlock;
use App\Models\UsageReservation;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Feature\PedagogyTestCase;

class AuditRoutingTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;

    public function test_audit_aprobado_plan_ai_crea_aprobacion_y_pasa_a_aprobada(): void
    {
        $scene = $this->succeededAuditScenario(true);

        $request = app(RouteAuditResult::class)->execute(
            $scene['audit'],
            null,
            '51515151-5151-4515-8515-515151515151',
        );

        $this->assertSame(PlanningRequestStatus::APROBADA, $request->status);
        $approval = Approval::query()->where('request_id', $request->id)->sole();
        $this->assertSame(ApprovalKind::Ai, $approval->kind);
        $this->assertSame($scene['version']->id, $approval->version_id);
        $this->assertSame($scene['audit']->id, $approval->ai_execution_id);
        $this->assertDatabaseHas('request_state_events', [
            'request_id' => $request->id,
            'from_status' => PlanningRequestStatus::AUDITORIA_IA->value,
            'to_status' => PlanningRequestStatus::APROBADA->value,
            'reason' => 'audit_passed_ai_approved',
        ]);
    }

    public function test_audit_aprobado_plan_revisado_pasa_a_revision_humana_sin_consumir_reserva(): void
    {
        $scene = $this->succeededAuditScenario(true, [
            'human_review_required' => true,
            'human_review_limit' => 8,
        ]);

        $request = app(RouteAuditResult::class)->execute($scene['audit']);

        $this->assertSame(PlanningRequestStatus::REVISION_HUMANA, $request->status);
        $this->assertDatabaseHas('approvals', [
            'request_id' => $request->id,
            'version_id' => $scene['version']->id,
            'kind' => ApprovalKind::Ai->value,
        ]);
        $human = UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::HumanReview->value)
            ->sole();
        $this->assertSame(UsageReservationStatus::Reserved, $human->status);
        $this->assertNull($human->consumed_at);
        $this->assertDatabaseHas('request_state_events', [
            'request_id' => $request->id,
            'to_status' => PlanningRequestStatus::REVISION_HUMANA->value,
            'reason' => 'audit_passed_human_review_required',
        ]);
    }

    public function test_audit_fallido_corregible_despacha_correccion_interna_sin_usar_cupo_cliente(): void
    {
        config(['ai.internal_correction.max_rounds' => 2]);
        $scene = $this->succeededAuditScenario(false, ['correction_limit' => 0]);

        $request = app(RouteAuditResult::class)->execute(
            $scene['audit'],
            null,
            '52525252-5252-4525-8525-525252525252',
        );

        $this->assertSame(PlanningRequestStatus::CORRECCION_IA, $request->status);
        $correction = AiExecution::query()
            ->where('request_id', $request->id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->sole();
        $this->assertSame(1, $correction->input_manifest['correction_round']);
        $this->assertSame(['sessions'], $correction->input_manifest['section_keys']);
        $this->assertDatabaseHas('outbox_events', [
            'type' => OutboxEventType::PlanningCorrectionRequested->value,
            'aggregate_id' => $request->id,
        ]);
        $this->assertSame(0, UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::ClientCorrection->value)
            ->count());
        $this->assertSame(0, $request->correction_limit_snapshot);
    }

    public function test_hallazgo_fuera_de_alcance_seguro_abre_bloqueo_y_no_crea_correccion(): void
    {
        $scene = $this->succeededAuditScenario(false, [], '/curricular_alignment/pdas/0', 'CURRICULUM_REFERENCE', 'high');

        $request = app(RouteAuditResult::class)->execute($scene['audit']);

        $this->assertSame(PlanningRequestStatus::AUDITORIA_IA, $request->status);
        $this->assertSame(0, AiExecution::query()
            ->where('request_id', $request->id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->count());
        $block = RequestBlock::query()
            ->where('request_id', $request->id)
            ->where('code', 'ai_quality_attention')
            ->whereNull('resolved_at')
            ->sole();
        $this->assertSame('AI_CORRECTION_SCOPE_UNSAFE', $block->details['reason']);
    }

    public function test_limite_interno_cero_abre_bloqueo_sin_consultar_correction_limit_comercial(): void
    {
        $scene = $this->succeededAuditScenario(false, ['correction_limit' => 9]);
        config(['ai.internal_correction.max_rounds' => 0]);

        $request = app(RouteAuditResult::class)->execute($scene['audit']);

        $this->assertSame(PlanningRequestStatus::AUDITORIA_IA, $request->status);
        $this->assertSame(9, $request->correction_limit_snapshot);
        $this->assertSame(0, AiExecution::query()
            ->where('request_id', $request->id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->count());
        $block = RequestBlock::query()->where('request_id', $request->id)
            ->where('code', 'ai_quality_attention')->whereNull('resolved_at')->sole();
        $this->assertSame('AI_INTERNAL_CORRECTION_ROUND_LIMIT_REACHED', $block->details['reason']);
    }

    public function test_route_repetido_es_idempotente(): void
    {
        $scene = $this->succeededAuditScenario(false);

        $first = app(RouteAuditResult::class)->execute(
            $scene['audit'],
            null,
            '53535353-5353-4535-8535-535353535353',
        );
        $second = app(RouteAuditResult::class)->execute(
            $scene['audit']->fresh(),
            null,
            '54545454-5454-4545-8545-545454545454',
        );

        $this->assertSame($first->status, $second->status);
        $this->assertSame(1, AiExecution::query()
            ->where('request_id', $first->id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->count());
        $this->assertSame(1, OutboxEvent::query()
            ->where('aggregate_id', $first->id)
            ->where('type', OutboxEventType::PlanningCorrectionRequested->value)
            ->count());
    }

    public function test_prompt_correction_incompatible_frena_ruteo_antes_de_cambiar_estado(): void
    {
        $scene = $this->succeededAuditScenario(false, [], '/sessions/0', 'LANGUAGE_QUALITY', 'medium', true);

        try {
            app(RouteAuditResult::class)->execute($scene['audit']);
            $this->fail('Prompt correction incompatible no debe congelar ejecución ni mover estado.');
        } catch (AiPipelineException $e) {
            $this->assertSame('AI_CORRECTION_PROMPT_SCHEMA_MISMATCH', $e->errorCode);
        }

        $this->assertSame(PlanningRequestStatus::AUDITORIA_IA, $scene['request']->fresh()->status);
        $this->assertSame(0, AiExecution::query()
            ->where('request_id', $scene['request']->id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->count());
    }


    public function test_reauditoria_fallida_crea_segunda_ronda_y_limite_interno_detiene_tercera(): void
    {
        config(['ai.internal_correction.max_rounds' => 2]);
        $scene = $this->succeededAuditScenario(false, ['correction_limit' => 0]);
        app(RouteAuditResult::class)->execute($scene['audit']);

        for ($round = 1; $round <= 2; $round++) {
            $correction = AiExecution::query()
                ->where('request_id', $scene['request']->id)
                ->where('stage', AiExecutionStage::Correction->value)
                ->where('input_manifest->correction_round', $round)
                ->sole();
            app(ProcessOutboxEvent::class)->execute(
                OutboxEvent::query()
                    ->where('type', OutboxEventType::PlanningCorrectionRequested->value)
                    ->where('payload->ai_execution_id', $correction->id)
                    ->sole(),
            );

            $sourceVersionId = (int) $correction->input_manifest['source_version_id'];
            $source = \App\Models\DocumentVersion::query()->findOrFail($sourceVersionId);
            $sessions = $source->content['sessions'];
            $sessions[0]['moments'][0]['activities'][0]['instruction'] = 'Corrección interna ronda ' . $round;
            $newVersion = app(ImportManualCorrectionResult::class)->execute($correction->fresh(), [
                'schema_version' => 'correction_result_v1',
                'source_version_id' => $sourceVersionId,
                'patch' => ['sessions' => $sessions],
            ]);

            $audit = AiExecution::query()
                ->where('request_id', $scene['request']->id)
                ->where('stage', AiExecutionStage::Audit->value)
                ->where('input_manifest->source_version_id', $newVersion->id)
                ->sole();
            app(ProcessOutboxEvent::class)->execute(
                OutboxEvent::query()
                    ->where('type', OutboxEventType::PlanningAuditRequested->value)
                    ->where('payload->ai_execution_id', $audit->id)
                    ->sole(),
            );
            app(ImportManualAuditResult::class)->execute($audit->fresh(), [
                'schema_version' => 'audit_result_v1',
                'passed' => false,
                'findings' => [[
                    'code' => 'LANGUAGE_QUALITY',
                    'severity' => 'medium',
                    'json_path' => '/sessions/0',
                    'explanation' => 'Persiste un hallazgo ficticio.',
                    'expected_correction' => 'Ajustar la sesión.',
                ]],
            ]);

            if ($round === 1) {
                app(RouteAuditResult::class)->execute($audit->fresh());
                $this->assertSame(PlanningRequestStatus::CORRECCION_IA, $scene['request']->fresh()->status);
            } else {
                $routed = app(RouteAuditResult::class)->execute($audit->fresh());
                $this->assertSame(PlanningRequestStatus::AUDITORIA_IA, $routed->status);
            }
        }

        $this->assertSame(2, AiExecution::query()
            ->where('request_id', $scene['request']->id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->count());
        $this->assertSame(0, UsageReservation::query()
            ->where('planning_request_id', $scene['request']->id)
            ->where('resource', UsageResource::ClientCorrection->value)
            ->count());
        $block = RequestBlock::query()->where('request_id', $scene['request']->id)
            ->where('code', 'ai_quality_attention')->whereNull('resolved_at')->sole();
        $this->assertSame('AI_INTERNAL_CORRECTION_ROUND_LIMIT_REACHED', $block->details['reason']);
    }

    public function test_audit_aprobado_ruteado_dos_veces_no_duplica_aprobacion(): void
    {
        $scene = $this->succeededAuditScenario(true);

        app(RouteAuditResult::class)->execute($scene['audit']);
        app(RouteAuditResult::class)->execute($scene['audit']->fresh());

        $this->assertSame(1, Approval::query()
            ->where('request_id', $scene['request']->id)
            ->where('kind', ApprovalKind::Ai->value)
            ->count());
        $this->assertSame(PlanningRequestStatus::APROBADA, $scene['request']->fresh()->status);
    }
}
