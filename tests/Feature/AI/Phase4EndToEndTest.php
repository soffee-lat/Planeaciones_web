<?php

namespace Tests\Feature\AI;

use App\Actions\AI\DispatchPlanningGeneration;
use App\Actions\AI\ImportManualAuditResult;
use App\Actions\AI\ImportManualCorrectionResult;
use App\Actions\AI\ImportManualGenerationResult;
use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\AI\RouteAuditResult;
use App\Enums\AiExecutionStage;
use App\Enums\ApprovalKind;
use App\Enums\OutboxEventType;
use App\Enums\PlanningRequestStatus;
use App\Enums\UsageReservationStatus;
use App\Enums\UsageResource;
use App\Models\AiExecution;
use App\Models\Approval;
use App\Models\DocumentVersion;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use App\Models\RequestStateEvent;
use App\Models\UsageReservation;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Feature\PedagogyTestCase;

class Phase4EndToEndTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;

    /** @return array{request:PlanningRequest,generation:AiExecution,version:DocumentVersion,audit:AiExecution,draft:array<string,mixed>,generation_event:OutboxEvent,audit_event:OutboxEvent} */
    private function generationToWaitingAudit(array $planLimits = []): array
    {
        config([
            'ai.mode' => 'manual',
            'ai.manual.disk' => 'private',
            'ai.manual.prefix' => 'ai/phase4f-test',
            'ai.internal_correction.max_rounds' => 2,
            'ai.internal_correction.max_known_cost' => null,
            'ai.internal_correction.cost_currency' => null,
        ]);
        Storage::fake('private');

        $request = $this->draft();
        $this->period($request, $planLimits);
        $request = $this->authorize($this->confirm($request));
        $this->publishManualAiPrompts();

        $generation = app(DispatchPlanningGeneration::class)->execute(
            $request,
            '81818181-8181-4818-8818-818181818181',
        );
        $generationEvent = OutboxEvent::query()
            ->where('aggregate_id', $request->id)
            ->where('type', OutboxEventType::PlanningGenerationRequested->value)
            ->where('payload->ai_execution_id', $generation->id)
            ->sole();
        app(ProcessOutboxEvent::class)->execute($generationEvent);

        $draft = $this->generatedDraftFor($request->fresh(['currentInputVersion']));
        $version = app(ImportManualGenerationResult::class)->execute($generation->fresh(), $draft);
        $audit = AiExecution::query()
            ->where('request_id', $request->id)
            ->where('stage', AiExecutionStage::Audit->value)
            ->where('input_manifest->source_version_id', $version->id)
            ->sole();
        $auditEvent = OutboxEvent::query()
            ->where('aggregate_id', $request->id)
            ->where('type', OutboxEventType::PlanningAuditRequested->value)
            ->where('payload->ai_execution_id', $audit->id)
            ->sole();
        app(ProcessOutboxEvent::class)->execute($auditEvent);

        return [
            'request' => $request->fresh(),
            'generation' => $generation->fresh(),
            'version' => $version->fresh(['document']),
            'audit' => $audit->fresh(),
            'draft' => $draft,
            'generation_event' => $generationEvent->fresh(),
            'audit_event' => $auditEvent->fresh(),
        ];
    }

    /** @return array{schema_version:string,passed:bool,findings:array<int,array<string,string>>} */
    private function failedAuditPayload(): array
    {
        return [
            'schema_version' => 'audit_result_v1',
            'passed' => false,
            'findings' => [[
                'code' => 'LANGUAGE_QUALITY',
                'severity' => 'medium',
                'json_path' => '/sessions/0',
                'explanation' => 'La primera actividad requiere una instrucción más precisa.',
                'expected_correction' => 'Aclarar la instrucción sin alterar contexto ni currículo.',
            ]],
        ];
    }

    /** @return array{schema_version:string,passed:bool,findings:array<int,mixed>} */
    private function passedAuditPayload(): array
    {
        return [
            'schema_version' => 'audit_result_v1',
            'passed' => true,
            'findings' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function correctionPayload(DocumentVersion $source): array
    {
        $sessions = $source->content['sessions'];
        $sessions[0]['moments'][0]['activities'][0]['instruction'] = 'Instrucción corregida, específica y verificable.';

        return [
            'schema_version' => 'correction_result_v1',
            'source_version_id' => $source->id,
            'patch' => [
                'sessions' => $sessions,
            ],
        ];
    }

    public function test_itinerario_ai_only_recorrido_completo_hasta_aprobada(): void
    {
        $scene = $this->generationToWaitingAudit();
        app(ImportManualAuditResult::class)->execute($scene['audit'], $this->passedAuditPayload());
        $request = app(RouteAuditResult::class)->execute($scene['audit']->fresh());

        $this->assertSame(PlanningRequestStatus::APROBADA, $request->status);
        $this->assertSame(1, DocumentVersion::query()->where('document_id', $scene['version']->document_id)->count());
        $this->assertDatabaseHas('approvals', [
            'request_id' => $request->id,
            'version_id' => $scene['version']->id,
            'ai_execution_id' => $scene['audit']->id,
            'kind' => ApprovalKind::Ai->value,
        ]);

        $planning = UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::Planning->value)
            ->sole();
        $this->assertSame(UsageReservationStatus::Consumed, $planning->status);
        $this->assertNotNull($planning->consumed_at);
        $this->assertSame(0, UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::HumanReview->value)
            ->count());

        $this->assertSame(
            [
                PlanningRequestStatus::GENERACION_IA->value,
                PlanningRequestStatus::AUDITORIA_IA->value,
                PlanningRequestStatus::APROBADA->value,
            ],
            RequestStateEvent::query()
                ->where('request_id', $request->id)
                ->whereIn('to_status', [
                    PlanningRequestStatus::GENERACION_IA->value,
                    PlanningRequestStatus::AUDITORIA_IA->value,
                    PlanningRequestStatus::APROBADA->value,
                ])
                ->orderBy('id')
                ->pluck('to_status')
                ->all(),
        );
    }

    public function test_itinerario_con_revision_humana_termina_en_cola_sin_consumir_reserva_humana(): void
    {
        $scene = $this->generationToWaitingAudit([
            'human_review_required' => true,
            'human_review_limit' => 8,
        ]);
        app(ImportManualAuditResult::class)->execute($scene['audit'], $this->passedAuditPayload());
        $request = app(RouteAuditResult::class)->execute($scene['audit']->fresh());

        $this->assertSame(PlanningRequestStatus::REVISION_HUMANA, $request->status);
        $this->assertDatabaseHas('approvals', [
            'request_id' => $request->id,
            'version_id' => $scene['version']->id,
            'kind' => ApprovalKind::Ai->value,
        ]);
        $this->assertSame(0, Approval::query()
            ->where('request_id', $request->id)
            ->where('kind', ApprovalKind::Human->value)
            ->count());

        $human = UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::HumanReview->value)
            ->sole();
        $this->assertSame(UsageReservationStatus::Reserved, $human->status);
        $this->assertNull($human->consumed_at);

        $planning = UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::Planning->value)
            ->sole();
        $this->assertSame(UsageReservationStatus::Consumed, $planning->status);
    }

    public function test_itinerario_correction_reaudit_crea_version_hija_y_aprueba_solo_la_version_actual(): void
    {
        $scene = $this->generationToWaitingAudit();
        app(ImportManualAuditResult::class)->execute($scene['audit'], $this->failedAuditPayload());
        $request = app(RouteAuditResult::class)->execute(
            $scene['audit']->fresh(),
            null,
            '82828282-8282-4828-8828-828282828282',
        );
        $this->assertSame(PlanningRequestStatus::CORRECCION_IA, $request->status);

        $correction = AiExecution::query()
            ->where('request_id', $request->id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->sole();
        $correctionEvent = OutboxEvent::query()
            ->where('aggregate_id', $request->id)
            ->where('type', OutboxEventType::PlanningCorrectionRequested->value)
            ->where('payload->ai_execution_id', $correction->id)
            ->sole();
        app(ProcessOutboxEvent::class)->execute($correctionEvent);

        $correctedVersion = app(ImportManualCorrectionResult::class)->execute(
            $correction->fresh(),
            $this->correctionPayload($scene['version']),
        );
        $this->assertSame(2, $correctedVersion->number);
        $this->assertSame($scene['version']->id, $correctedVersion->parent_version_id);
        $this->assertSame($correctedVersion->id, $correctedVersion->document->fresh()->current_version_id);

        $secondAudit = AiExecution::query()
            ->where('request_id', $request->id)
            ->where('stage', AiExecutionStage::Audit->value)
            ->where('input_manifest->source_version_id', $correctedVersion->id)
            ->sole();
        $secondAuditEvent = OutboxEvent::query()
            ->where('aggregate_id', $request->id)
            ->where('type', OutboxEventType::PlanningAuditRequested->value)
            ->where('payload->ai_execution_id', $secondAudit->id)
            ->sole();
        app(ProcessOutboxEvent::class)->execute($secondAuditEvent);
        app(ImportManualAuditResult::class)->execute($secondAudit->fresh(), $this->passedAuditPayload());
        $request = app(RouteAuditResult::class)->execute($secondAudit->fresh());

        $this->assertSame(PlanningRequestStatus::APROBADA, $request->status);
        $this->assertSame(2, DocumentVersion::query()->where('document_id', $scene['version']->document_id)->count());
        $this->assertSame(2, AiExecution::query()
            ->where('request_id', $request->id)
            ->where('stage', AiExecutionStage::Audit->value)
            ->count());
        $this->assertSame(1, AiExecution::query()
            ->where('request_id', $request->id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->count());
        $this->assertDatabaseMissing('approvals', [
            'request_id' => $request->id,
            'version_id' => $scene['version']->id,
        ]);
        $this->assertDatabaseHas('approvals', [
            'request_id' => $request->id,
            'version_id' => $correctedVersion->id,
            'ai_execution_id' => $secondAudit->id,
            'kind' => ApprovalKind::Ai->value,
        ]);

        $this->assertSame(
            [
                PlanningRequestStatus::GENERACION_IA->value,
                PlanningRequestStatus::AUDITORIA_IA->value,
                PlanningRequestStatus::CORRECCION_IA->value,
                PlanningRequestStatus::AUDITORIA_IA->value,
                PlanningRequestStatus::APROBADA->value,
            ],
            RequestStateEvent::query()
                ->where('request_id', $request->id)
                ->whereIn('to_status', [
                    PlanningRequestStatus::GENERACION_IA->value,
                    PlanningRequestStatus::AUDITORIA_IA->value,
                    PlanningRequestStatus::CORRECCION_IA->value,
                    PlanningRequestStatus::APROBADA->value,
                ])
                ->orderBy('id')
                ->pluck('to_status')
                ->all(),
        );
    }

    public function test_replays_del_pipeline_cerrado_no_duplican_version_aprobacion_consumo_ni_outbox(): void
    {
        $scene = $this->generationToWaitingAudit();
        $auditPayload = $this->passedAuditPayload();
        app(ImportManualAuditResult::class)->execute($scene['audit'], $auditPayload);
        app(RouteAuditResult::class)->execute($scene['audit']->fresh());

        $replayedVersion = app(ImportManualGenerationResult::class)->execute(
            $scene['generation']->fresh(),
            $scene['draft'],
        );
        app(ImportManualAuditResult::class)->execute($scene['audit']->fresh(), $auditPayload);
        $replayedRequest = app(RouteAuditResult::class)->execute($scene['audit']->fresh());

        $this->assertSame($scene['version']->id, $replayedVersion->id);
        $this->assertSame(PlanningRequestStatus::APROBADA, $replayedRequest->status);
        $this->assertFalse(app(ProcessOutboxEvent::class)->execute($scene['generation_event']->fresh()));
        $this->assertFalse(app(ProcessOutboxEvent::class)->execute($scene['audit_event']->fresh()));
        $this->assertSame(1, DocumentVersion::query()->where('document_id', $scene['version']->document_id)->count());
        $this->assertSame(1, Approval::query()->where('request_id', $scene['request']->id)->count());
        $this->assertSame(1, RequestStateEvent::query()
            ->where('request_id', $scene['request']->id)
            ->where('to_status', PlanningRequestStatus::APROBADA->value)
            ->count());

        $planning = UsageReservation::query()
            ->where('planning_request_id', $scene['request']->id)
            ->where('resource', UsageResource::Planning->value)
            ->sole();
        $this->assertSame(UsageReservationStatus::Consumed, $planning->status);
    }
}
