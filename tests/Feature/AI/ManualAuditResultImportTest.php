<?php

namespace Tests\Feature\AI;

use App\Actions\AI\DispatchPlanningGeneration;
use App\Actions\AI\ImportManualAuditResult;
use App\Actions\AI\ImportManualGenerationResult;
use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\AI\PublishPromptVersion;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Enums\OutboxEventType;
use App\Enums\PlanningRequestStatus;
use App\Enums\PromptCategory;
use App\Exceptions\AiContractException;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use App\Models\DocumentVersion;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Feature\PedagogyTestCase;

class ManualAuditResultImportTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;

    /** @return array{0:PlanningRequest,1:AiExecution,2:DocumentVersion} */
    private function waitingAudit(): array
    {
        config([
            'ai.mode' => 'manual',
            'ai.manual.disk' => 'private',
            'ai.manual.prefix' => 'ai/audit-result-test',
        ]);
        Storage::fake('private');
        $request = $this->draft();
        $this->period($request);
        $request = $this->authorize($this->confirm($request));
        $this->publishPrompts();

        $generation = app(DispatchPlanningGeneration::class)->execute(
            $request,
            '99999999-9999-4999-8999-999999999999',
        );
        app(ProcessOutboxEvent::class)->execute(OutboxEvent::query()
            ->where('type', OutboxEventType::PlanningGenerationRequested->value)->sole());
        $version = app(ImportManualGenerationResult::class)->execute(
            $generation->fresh(),
            $this->generatedDraftFor($request->fresh(['currentInputVersion'])),
        );
        $audit = AiExecution::query()->where('request_id', $request->id)->where('stage', AiExecutionStage::Audit->value)->sole();
        app(ProcessOutboxEvent::class)->execute(OutboxEvent::query()
            ->where('type', OutboxEventType::PlanningAuditRequested->value)->sole());

        return [$request->fresh(), $audit->fresh(['manualPackage']), $version];
    }

    public function test_importa_auditoria_aprobada_y_conserva_estado_para_decision_4e(): void
    {
        [$request, $audit, $version] = $this->waitingAudit();
        $result = app(ImportManualAuditResult::class)->execute($audit, $this->passedPayload());

        $audit = $audit->fresh();
        $this->assertTrue($result->passed);
        $this->assertSame(AiExecutionStatus::Succeeded, $audit->status);
        $this->assertSame('audit_result_v1', $audit->audit_report['schema_version']);
        $this->assertTrue($audit->audit_report['passed']);
        $this->assertNull($audit->resulting_version_id);
        $this->assertNotNull($audit->finished_at);
        $this->assertSame(PlanningRequestStatus::AUDITORIA_IA, $request->fresh()->status);
        $this->assertSame($version->id, $request->fresh('document')->document->current_version_id);
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_importa_auditoria_fallida_con_hallazgos_estructurados(): void
    {
        [, $audit] = $this->waitingAudit();
        $result = app(ImportManualAuditResult::class)->execute($audit, $this->failedPayload());

        $this->assertFalse($result->passed);
        $this->assertCount(1, $result->findings);
        $this->assertSame('high', $audit->fresh()->audit_report['findings'][0]['severity']);
        $this->assertSame('/sessions/0', $audit->fresh()->audit_report['findings'][0]['json_path']);
    }

    public function test_payload_invalido_no_cierra_execution(): void
    {
        [, $audit] = $this->waitingAudit();
        $bad = $this->passedPayload();
        $bad['findings'][] = [
            'code' => 'SCHEMA',
            'severity' => 'high',
            'json_path' => '$',
            'explanation' => 'Hallazgo incompatible con passed=true.',
            'expected_correction' => 'Corregir.',
        ];

        try {
            app(ImportManualAuditResult::class)->execute($audit, $bad);
            $this->fail('Debe rechazar passed=true con hallazgos.');
        } catch (AiContractException $e) {
            $this->assertStringContainsString('AI_AUDIT_PASSED_WITH_FINDINGS', $e->getMessage());
        }

        $this->assertSame(AiExecutionStatus::WaitingManual, $audit->fresh()->status);
        $this->assertNull($audit->fresh()->audit_report);
    }

    public function test_reintento_mismo_reporte_es_idempotente_y_distinto_conflicta(): void
    {
        [, $audit] = $this->waitingAudit();
        $action = app(ImportManualAuditResult::class);
        $first = $action->execute($audit, $this->failedPayload());
        $second = $action->execute($audit->fresh(), $this->failedPayload());

        $this->assertSame($first->toArray(), $second->toArray());

        try {
            $action->execute($audit->fresh(), $this->passedPayload());
            $this->fail('Una auditoría cerrada no admite un resultado diferente.');
        } catch (AiPipelineException $e) {
            $this->assertSame('AI_AUDIT_RESULT_IDEMPOTENCY_CONFLICT', $e->errorCode);
        }
    }

    public function test_metadata_real_se_guarda_y_desconocida_permanece_null(): void
    {
        [, $audit] = $this->waitingAudit();
        app(ImportManualAuditResult::class)->execute(
            $audit,
            $this->passedPayload(),
            'Proveedor audit',
            'modelo-audit-1',
            '0.00123456',
            'mxn',
        );

        $audit = $audit->fresh();
        $this->assertSame('Proveedor audit', $audit->provider);
        $this->assertSame('modelo-audit-1', $audit->model);
        $this->assertSame('0.00123456', $audit->actual_cost);
        $this->assertSame('MXN', $audit->cost_currency);
    }

    /** @return array<string,mixed> */
    private function passedPayload(): array
    {
        return ['schema_version' => 'audit_result_v1', 'passed' => true, 'findings' => []];
    }

    /** @return array<string,mixed> */
    private function failedPayload(): array
    {
        return [
            'schema_version' => 'audit_result_v1',
            'passed' => false,
            'findings' => [[
                'code' => 'PEDAGOGICAL_ALIGNMENT',
                'severity' => 'high',
                'json_path' => '/sessions/0',
                'explanation' => 'La actividad no demuestra alineación suficiente con la meta declarada.',
                'expected_correction' => 'Reformular la actividad para hacer explícita la relación con la meta.',
            ]],
        ];
    }

    private function publishPrompts(): void
    {
        $generationTemplate = PromptTemplate::factory()->create(['key' => 'planning.generation', 'category' => PromptCategory::Generation->value]);
        $generation = PromptVersion::factory()->create([
            'template_id' => $generationTemplate->id,
            'body' => 'INPUT={{input_snapshot}} OUTPUT={{output_schema}}',
            'allowed_variables' => ['input_snapshot', 'output_schema'],
            'output_schema' => json_decode(file_get_contents(resource_path('schemas/ai/generated_plan_draft_v1.schema.json')), true, 512, JSON_THROW_ON_ERROR),
            'schema_version' => 'generated_plan_draft_v1',
        ]);
        app(PublishPromptVersion::class)->execute($this->admin(), $generation);

        $auditTemplate = PromptTemplate::factory()->create(['key' => 'planning.audit', 'category' => PromptCategory::Audit->value]);
        $audit = PromptVersion::factory()->create([
            'template_id' => $auditTemplate->id,
            'body' => 'CANONICAL={{canonical_plan}} OUTPUT={{output_schema}}',
            'allowed_variables' => ['canonical_plan', 'output_schema'],
            'output_schema' => json_decode(file_get_contents(resource_path('schemas/ai/audit_result_v1.schema.json')), true, 512, JSON_THROW_ON_ERROR),
            'schema_version' => 'audit_result_v1',
        ]);
        app(PublishPromptVersion::class)->execute($this->admin(), $audit);
    }
}
