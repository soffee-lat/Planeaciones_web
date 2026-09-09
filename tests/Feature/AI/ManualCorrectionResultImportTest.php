<?php

namespace Tests\Feature\AI;

use App\Actions\AI\ImportManualAuditResult;
use App\Actions\AI\ImportManualCorrectionResult;
use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\AI\RouteAuditResult;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Enums\OutboxEventType;
use App\Enums\PlanningRequestStatus;
use App\Exceptions\AiContractException;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use App\Models\DocumentVersion;
use App\Models\OutboxEvent;
use App\Support\AI\CanonicalJson;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Feature\PedagogyTestCase;

class ManualCorrectionResultImportTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;

    /** @return array{request:\App\Models\PlanningRequest,audit:AiExecution,source:DocumentVersion,correction:AiExecution} */
    private function waitingCorrection(): array
    {
        $scene = $this->succeededAuditScenario(false);
        $request = app(RouteAuditResult::class)->execute(
            $scene['audit'],
            null,
            '71717171-7171-4717-8717-717171717171',
        );
        $correction = AiExecution::query()
            ->where('request_id', $request->id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->sole();
        app(ProcessOutboxEvent::class)->execute(
            OutboxEvent::query()
                ->where('aggregate_id', $request->id)
                ->where('type', OutboxEventType::PlanningCorrectionRequested->value)
                ->sole(),
        );

        return [
            'request' => $request->fresh(),
            'audit' => $scene['audit']->fresh(),
            'source' => $scene['version']->fresh(),
            'correction' => $correction->fresh(),
        ];
    }

    /** @return array<string,mixed> */
    private function sessionsCorrection(DocumentVersion $source, string $instruction = 'Instrucción corregida y más precisa.'): array
    {
        $sessions = $source->content['sessions'];
        $sessions[0]['moments'][0]['activities'][0]['instruction'] = $instruction;

        return [
            'schema_version' => 'correction_result_v1',
            'source_version_id' => $source->id,
            'patch' => [
                'sessions' => $sessions,
            ],
        ];
    }

    public function test_import_correction_crea_version_hija_y_prepara_reauditoria(): void
    {
        $scene = $this->waitingCorrection();
        $version = app(ImportManualCorrectionResult::class)->execute(
            $scene['correction'],
            $this->sessionsCorrection($scene['source']),
        );

        $this->assertSame(2, $version->number);
        $this->assertSame($scene['source']->id, $version->parent_version_id);
        $this->assertSame($scene['correction']->id, $version->ai_execution_id);
        $this->assertSame('Instrucción corregida y más precisa.', $version->content['sessions'][0]['moments'][0]['activities'][0]['instruction']);
        $this->assertSame($version->id, $version->document->current_version_id);
        $this->assertSame(AiExecutionStatus::Succeeded, $scene['correction']->fresh()->status);
        $this->assertSame($version->id, $scene['correction']->fresh()->resulting_version_id);
        $this->assertSame(PlanningRequestStatus::AUDITORIA_IA, $scene['request']->fresh()->status);

        $audit = AiExecution::query()
            ->where('request_id', $scene['request']->id)
            ->where('stage', AiExecutionStage::Audit->value)
            ->where('input_manifest->source_version_id', $version->id)
            ->sole();
        $this->assertSame(AiExecutionStatus::Pending, $audit->status);
        $this->assertDatabaseHas('outbox_events', [
            'type' => OutboxEventType::PlanningAuditRequested->value,
            'aggregate_id' => $scene['request']->id,
        ]);
        $this->assertDatabaseHas('request_state_events', [
            'request_id' => $scene['request']->id,
            'from_status' => PlanningRequestStatus::CORRECCION_IA->value,
            'to_status' => PlanningRequestStatus::AUDITORIA_IA->value,
            'reason' => 'correction_result_imported_reaudit',
        ]);
    }

    public function test_correction_preserva_source_context_y_curriculo_server_side(): void
    {
        $scene = $this->waitingCorrection();
        $version = app(ImportManualCorrectionResult::class)->execute(
            $scene['correction'],
            $this->sessionsCorrection($scene['source']),
        );

        foreach (['source', 'context', 'curricular_alignment'] as $root) {
            $this->assertSame(
                CanonicalJson::hash($scene['source']->content[$root]),
                CanonicalJson::hash($version->content[$root]),
                $root,
            );
        }
    }

    public function test_patch_fuera_del_section_scope_se_rechaza_sin_crear_version(): void
    {
        $scene = $this->waitingCorrection();
        $payload = [
            'schema_version' => 'correction_result_v1',
            'source_version_id' => $scene['source']->id,
            'patch' => [
                'resources' => $scene['source']->content['resources'],
            ],
        ];

        try {
            app(ImportManualCorrectionResult::class)->execute($scene['correction'], $payload);
            $this->fail('No debe corregirse una sección fuera de section_keys.');
        } catch (AiContractException $e) {
            $this->assertSame('AI_CORRECTION_PATCH_OUTSIDE_SCOPE', $e->errorCode);
        }

        $this->assertSame(1, DocumentVersion::query()->where('document_id', $scene['source']->document_id)->count());
        $this->assertSame(AiExecutionStatus::WaitingManual, $scene['correction']->fresh()->status);
        $this->assertSame(PlanningRequestStatus::CORRECCION_IA, $scene['request']->fresh()->status);
    }


    public function test_correction_no_puede_introducir_referencia_curricular_ajena(): void
    {
        $scene = $this->waitingCorrection();
        $payload = $this->sessionsCorrection($scene['source']);
        $payload['patch']['sessions'][0]['content_codes'] = ['CONTENT-NO-AUTORIZADO'];

        try {
            app(ImportManualCorrectionResult::class)->execute($scene['correction'], $payload);
            $this->fail('La corrección no puede ampliar el currículo congelado.');
        } catch (AiContractException $e) {
            $this->assertSame('GENERATED_CONTENT_REFERENCE_NOT_ALLOWED', $e->errorCode);
        }

        $this->assertSame(1, DocumentVersion::query()->where('document_id', $scene['source']->document_id)->count());
        $this->assertSame(PlanningRequestStatus::CORRECCION_IA, $scene['request']->fresh()->status);
    }

    public function test_source_version_distinta_se_rechaza(): void
    {
        $scene = $this->waitingCorrection();
        $payload = $this->sessionsCorrection($scene['source']);
        $payload['source_version_id'] = $scene['source']->id + 999;

        try {
            app(ImportManualCorrectionResult::class)->execute($scene['correction'], $payload);
            $this->fail('Debe usar la versión fuente exacta del manifest.');
        } catch (AiPipelineException $e) {
            $this->assertSame('AI_CORRECTION_RESULT_SOURCE_VERSION_MISMATCH', $e->errorCode);
        }
    }

    public function test_mismo_resultado_es_idempotente_y_resultado_distinto_conflicta(): void
    {
        $scene = $this->waitingCorrection();
        $payload = $this->sessionsCorrection($scene['source']);

        $first = app(ImportManualCorrectionResult::class)->execute($scene['correction'], $payload);
        $second = app(ImportManualCorrectionResult::class)->execute($scene['correction']->fresh(), $payload);
        $this->assertSame($first->id, $second->id);

        try {
            app(ImportManualCorrectionResult::class)->execute(
                $scene['correction']->fresh(),
                $this->sessionsCorrection($scene['source'], 'Otro resultado incompatible.'),
            );
            $this->fail('Una ejecución cerrada no puede aceptar otro resultado.');
        } catch (AiPipelineException $e) {
            $this->assertSame('AI_CORRECTION_RESULT_IDEMPOTENCY_CONFLICT', $e->errorCode);
        }
    }

    public function test_metadata_real_opcional_se_congela_en_ejecucion(): void
    {
        $scene = $this->waitingCorrection();
        app(ImportManualCorrectionResult::class)->execute(
            $scene['correction'],
            $this->sessionsCorrection($scene['source']),
            'manual-provider',
            'manual-model',
            '0.12500000',
            'mxn',
        );

        $execution = $scene['correction']->fresh();
        $this->assertSame('manual-provider', $execution->provider);
        $this->assertSame('manual-model', $execution->model);
        $this->assertSame('0.12500000', $execution->actual_cost);
        $this->assertSame('MXN', $execution->cost_currency);
    }

    public function test_ciclo_correction_reaudit_pass_route_termina_en_aprobada(): void
    {
        $scene = $this->waitingCorrection();
        $version = app(ImportManualCorrectionResult::class)->execute(
            $scene['correction'],
            $this->sessionsCorrection($scene['source']),
        );
        $audit = AiExecution::query()
            ->where('request_id', $scene['request']->id)
            ->where('stage', AiExecutionStage::Audit->value)
            ->where('input_manifest->source_version_id', $version->id)
            ->sole();
        app(ProcessOutboxEvent::class)->execute(
            OutboxEvent::query()
                ->where('type', OutboxEventType::PlanningAuditRequested->value)
                ->where('payload->ai_execution_id', $audit->id)
                ->sole(),
        );
        app(ImportManualAuditResult::class)->execute($audit->fresh(), [
            'schema_version' => 'audit_result_v1',
            'passed' => true,
            'findings' => [],
        ]);

        $request = app(RouteAuditResult::class)->execute($audit->fresh());

        $this->assertSame(PlanningRequestStatus::APROBADA, $request->status);
        $this->assertDatabaseHas('approvals', [
            'request_id' => $request->id,
            'version_id' => $version->id,
            'ai_execution_id' => $audit->id,
            'kind' => 'ai',
        ]);
    }
}
