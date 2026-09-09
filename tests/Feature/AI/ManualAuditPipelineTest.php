<?php

namespace Tests\Feature\AI;

use App\Actions\AI\DispatchPlanningGeneration;
use App\Actions\AI\ImportManualGenerationResult;
use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\AI\PublishPromptVersion;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Enums\OutboxEventType;
use App\Enums\PlanningRequestStatus;
use App\Enums\PromptCategory;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use App\Models\RequestBlock;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Feature\PedagogyTestCase;

class ManualAuditPipelineTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;

    /** @return array{0:PlanningRequest,1:AiExecution,2:OutboxEvent} */
    private function pendingAudit(): array
    {
        config([
            'ai.mode' => 'manual',
            'ai.manual.disk' => 'private',
            'ai.manual.prefix' => 'ai/audit-pipeline-test',
        ]);
        Storage::fake('private');

        $request = $this->draft();
        $this->period($request);
        $request = $this->authorize($this->confirm($request));
        $this->publishGenerationPrompt();
        $this->publishAuditPrompt();

        $generation = app(DispatchPlanningGeneration::class)->execute(
            $request,
            '88888888-8888-4888-8888-888888888888',
        );
        $generationEvent = OutboxEvent::query()
            ->where('type', OutboxEventType::PlanningGenerationRequested->value)
            ->where('aggregate_id', $request->id)
            ->sole();
        app(ProcessOutboxEvent::class)->execute($generationEvent);

        $payload = $this->generatedDraftFor($request->fresh(['currentInputVersion']));
        $version = app(ImportManualGenerationResult::class)->execute($generation->fresh(), $payload);
        $audit = AiExecution::query()
            ->where('request_id', $request->id)
            ->where('stage', AiExecutionStage::Audit->value)
            ->sole();
        $event = OutboxEvent::query()
            ->where('type', OutboxEventType::PlanningAuditRequested->value)
            ->where('aggregate_id', $request->id)
            ->sole();

        $this->assertSame($version->id, $event->payload['source_version_id']);

        return [$request->fresh(), $audit, $event];
    }

    public function test_importar_generacion_crea_outbox_de_auditoria_sin_procesarlo(): void
    {
        [$request, $audit, $event] = $this->pendingAudit();

        $this->assertSame(PlanningRequestStatus::AUDITORIA_IA, $request->status);
        $this->assertSame(AiExecutionStatus::Pending, $audit->status);
        $this->assertNull($event->published_at);
        $this->assertNull($audit->manualPackage);
    }

    public function test_procesar_outbox_audit_crea_paquete_privado_y_waiting_manual(): void
    {
        [, $audit, $event] = $this->pendingAudit();

        $this->assertTrue(app(ProcessOutboxEvent::class)->execute($event));
        $audit = $audit->fresh(['manualPackage']);

        $this->assertSame(AiExecutionStatus::WaitingManual, $audit->status);
        $this->assertNotNull($audit->rendered_prompt_hash);
        $this->assertNotNull($audit->manualPackage);
        $this->assertStringContainsString('/audit/execution-' . $audit->id . '.json', $audit->manualPackage->path);
        $this->assertTrue(Storage::disk('private')->exists($audit->manualPackage->path));

        $package = json_decode(Storage::disk('private')->get($audit->manualPackage->path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('manual_audit', $package['kind']);
        $this->assertSame($audit->input_manifest['source_version_id'], $package['source']['document_version_id']);
        $this->assertSame($audit->input_manifest['source_content_hash'], $package['source']['content_sha256']);
        $this->assertSame('canonical_plan_v1', $package['source']['canonical_plan']['schema_version']);
        $this->assertSame('audit_result_v1', $package['output']['schema_version']);
    }

    public function test_outbox_audit_publicado_es_idempotente(): void
    {
        [, $audit, $event] = $this->pendingAudit();
        $processor = app(ProcessOutboxEvent::class);

        $this->assertTrue($processor->execute($event));
        $this->assertFalse($processor->execute($event->fresh()));
        $this->assertSame(1, \App\Models\AiManualPackage::query()->where('ai_execution_id', $audit->id)->count());
    }

    public function test_fallo_de_paquete_audit_abre_bloqueo_en_stage_audit(): void
    {
        [$request, $audit, $event] = $this->pendingAudit();
        config(['ai.manual.disk' => 'missing-private-disk']);

        try {
            app(ProcessOutboxEvent::class)->execute($event);
            $this->fail('Debió fallar al preparar paquete audit sin disco privado válido.');
        } catch (AiPipelineException $e) {
            $this->assertSame('AI_MANUAL_PRIVATE_DISK_INVALID', $e->errorCode);
        }

        $block = RequestBlock::query()->where('request_id', $request->id)->whereNull('resolved_at')->sole();
        $this->assertSame('ai_failed', $block->code);
        $this->assertSame(AiExecutionStage::Audit->value, $block->stage);
        $this->assertSame('AI_MANUAL_PRIVATE_DISK_INVALID', $audit->fresh()->error_code);
        $this->assertNull($event->fresh()->published_at);
    }

    public function test_prompt_audit_incompatible_frena_import_de_generacion_antes_de_crear_documento(): void
    {
        config([
            'ai.mode' => 'manual',
            'ai.manual.disk' => 'private',
            'ai.manual.prefix' => 'ai/audit-prompt-invalid-test',
        ]);
        Storage::fake('private');
        $request = $this->draft();
        $this->period($request);
        $request = $this->authorize($this->confirm($request));
        $this->publishGenerationPrompt();

        $template = PromptTemplate::factory()->create([
            'key' => 'planning.audit',
            'category' => PromptCategory::Audit->value,
        ]);
        $bad = PromptVersion::factory()->create([
            'template_id' => $template->id,
            'body' => 'CANONICAL={{canonical_plan}} OUTPUT={{output_schema}}',
            'allowed_variables' => ['canonical_plan', 'output_schema'],
            'output_schema' => ['type' => 'object'],
            'schema_version' => 'audit_result_v1',
        ]);
        app(PublishPromptVersion::class)->execute($this->admin(), $bad);

        $generation = app(DispatchPlanningGeneration::class)->execute($request);
        $generationEvent = OutboxEvent::query()->where('type', OutboxEventType::PlanningGenerationRequested->value)->sole();
        app(ProcessOutboxEvent::class)->execute($generationEvent);
        $payload = $this->generatedDraftFor($request->fresh(['currentInputVersion']));

        try {
            app(ImportManualGenerationResult::class)->execute($generation->fresh(), $payload);
            $this->fail('Un prompt audit incompatible no debe congelarse en una ejecución inmutable.');
        } catch (AiPipelineException $e) {
            $this->assertSame('AI_AUDIT_PROMPT_SCHEMA_MISMATCH', $e->errorCode);
        }

        $this->assertSame(PlanningRequestStatus::GENERACION_IA, $request->fresh()->status);
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame(AiExecutionStatus::WaitingManual, $generation->fresh()->status);
    }

    private function publishGenerationPrompt(): PromptVersion
    {
        $template = PromptTemplate::factory()->create([
            'key' => 'planning.generation',
            'category' => PromptCategory::Generation->value,
        ]);
        $schema = json_decode(file_get_contents(resource_path('schemas/ai/generated_plan_draft_v1.schema.json')), true, 512, JSON_THROW_ON_ERROR);
        $version = PromptVersion::factory()->create([
            'template_id' => $template->id,
            'body' => 'INPUT={{input_snapshot}} OUTPUT={{output_schema}}',
            'allowed_variables' => ['input_snapshot', 'output_schema'],
            'output_schema' => $schema,
            'schema_version' => 'generated_plan_draft_v1',
        ]);

        return app(PublishPromptVersion::class)->execute($this->admin(), $version);
    }

    private function publishAuditPrompt(): PromptVersion
    {
        $template = PromptTemplate::factory()->create([
            'key' => 'planning.audit',
            'category' => PromptCategory::Audit->value,
        ]);
        $schema = json_decode(file_get_contents(resource_path('schemas/ai/audit_result_v1.schema.json')), true, 512, JSON_THROW_ON_ERROR);
        $version = PromptVersion::factory()->create([
            'template_id' => $template->id,
            'body' => 'CANONICAL={{canonical_plan}} OUTPUT={{output_schema}}',
            'allowed_variables' => ['canonical_plan', 'output_schema'],
            'output_schema' => $schema,
            'schema_version' => 'audit_result_v1',
        ]);

        return app(PublishPromptVersion::class)->execute($this->admin(), $version);
    }
}
