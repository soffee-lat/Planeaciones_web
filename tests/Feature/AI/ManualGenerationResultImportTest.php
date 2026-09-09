<?php

namespace Tests\Feature\AI;

use App\Actions\AI\DispatchPlanningGeneration;
use App\Actions\AI\ImportManualGenerationResult;
use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\AI\PublishPromptVersion;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Enums\DocumentVersionStatus;
use App\Enums\PlanningRequestStatus;
use App\Enums\PromptCategory;
use App\Enums\UsageReservationStatus;
use App\Enums\UsageResource;
use App\Exceptions\AiContractException;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use App\Models\RequestStateEvent;
use App\Models\UsageReservation;
use App\Support\AI\CanonicalJson;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Feature\PedagogyTestCase;

class ManualGenerationResultImportTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;

    private function readyRequest(array $limits = []): PlanningRequest
    {
        $request = $this->draft();
        $this->period($request, $limits);

        return $this->authorize($this->confirm($request));
    }

    private function publishGenerationPrompt(): PromptVersion
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

    /** @return array{0:PlanningRequest,1:AiExecution} */
    private function waitingExecution(array $limits = [], bool $withAuditPrompt = true): array
    {
        config([
            'ai.mode' => 'manual',
            'ai.manual.disk' => 'private',
            'ai.manual.prefix' => 'ai/manual-result-test',
        ]);
        Storage::fake('private');

        $request = $this->readyRequest($limits);
        $this->publishGenerationPrompt();
        if ($withAuditPrompt) {
            $this->publishAuditPrompt();
        }

        $execution = app(DispatchPlanningGeneration::class)->execute(
            $request,
            '66666666-6666-4666-8666-666666666666',
        );
        $event = OutboxEvent::query()->where('aggregate_id', $request->id)->sole();
        app(ProcessOutboxEvent::class)->execute($event);

        return [$request->fresh(), $execution->fresh(['manualPackage'])];
    }

    public function test_importa_draft_valido_crea_documento_version_y_prepara_auditoria(): void
    {
        [$request, $execution] = $this->waitingExecution();
        $payload = $this->generatedDraftFor($request->fresh(['currentInputVersion']));

        $version = app(ImportManualGenerationResult::class)->execute($execution, $payload);

        $request = $request->fresh(['document.currentVersion', 'aiExecutions']);
        $execution = $execution->fresh(['resultingVersion']);
        $this->assertSame(PlanningRequestStatus::AUDITORIA_IA, $request->status);
        $this->assertNotNull($request->document);
        $this->assertSame($version->id, $request->document->current_version_id);
        $this->assertSame(DocumentVersionStatus::Validated, $version->status);
        $this->assertSame('canonical_plan_v1', $version->content['schema_version']);
        $this->assertSame($request->id, $version->content['source']['planning_request_id']);
        $this->assertSame($request->input_revision, $version->input_revision);
        $this->assertSame(CanonicalJson::hash($version->content), $version->content_hash);
        $this->assertSame(CanonicalJson::hash($payload), $version->source_payload_hash);

        $this->assertSame(AiExecutionStatus::Succeeded, $execution->status);
        $this->assertSame($version->id, $execution->resulting_version_id);
        $this->assertNotNull($execution->finished_at);
        $this->assertNull($execution->provider);
        $this->assertNull($execution->model);
        $this->assertNull($execution->actual_cost);
        $this->assertNull($execution->cost_currency);

        $audit = AiExecution::query()
            ->where('request_id', $request->id)
            ->where('stage', AiExecutionStage::Audit->value)
            ->sole();
        $this->assertSame(AiExecutionStatus::Pending, $audit->status);
        $this->assertSame($version->id, $audit->input_manifest['source_version_id']);
        $this->assertSame($version->content_hash, $audit->input_manifest['source_content_hash']);
        $this->assertSame('66666666-6666-4666-8666-666666666666', $audit->input_manifest['correlation_id']);

        $state = RequestStateEvent::query()
            ->where('request_id', $request->id)
            ->where('to_status', PlanningRequestStatus::AUDITORIA_IA->value)
            ->sole();
        $this->assertSame('generation_result_imported', $state->reason);
        $this->assertSame('system', $state->actor_type);
    }

    public function test_curriculo_del_documento_proviene_del_snapshot_congelado(): void
    {
        [$request, $execution] = $this->waitingExecution();
        $request = $request->fresh(['currentInputVersion']);
        $payload = $this->generatedDraftFor($request);
        $payload['project_name'] = 'Proyecto inventado por proveedor';
        $payload['resources']['provided_references'] = ['Referencia inventada'];

        $version = app(ImportManualGenerationResult::class)->execute($execution, $payload);
        $snapshot = $request->currentInputVersion->snapshot;

        $this->assertSame($snapshot['curriculum']['contents'][0]['full_text'], $version->content['curricular_alignment']['contents'][0]['full_text']);
        $this->assertSame($snapshot['curriculum']['pdas'][0]['full_text'], $version->content['curricular_alignment']['pdas'][0]['full_text']);
        $this->assertSame($snapshot['request']['project'], $version->content['planning']['project_name']);
        $this->assertNotContains('Referencia inventada', $version->content['resources']['provided_references']);
    }

    public function test_metadata_real_se_guarda_y_valores_desconocidos_permanecen_null(): void
    {
        [$request, $execution] = $this->waitingExecution();
        $payload = $this->generatedDraftFor($request->fresh(['currentInputVersion']));

        app(ImportManualGenerationResult::class)->execute(
            $execution,
            $payload,
            'Proveedor real',
            'modelo-real-1',
            '0.01234567',
            'mxn',
        );

        $execution = $execution->fresh();
        $this->assertSame('Proveedor real', $execution->provider);
        $this->assertSame('modelo-real-1', $execution->model);
        $this->assertSame('0.01234567', $execution->actual_cost);
        $this->assertSame('MXN', $execution->cost_currency);
    }

    public function test_reintento_mismo_payload_es_idempotente_sin_version_ni_auditoria_duplicada(): void
    {
        [$request, $execution] = $this->waitingExecution();
        $payload = $this->generatedDraftFor($request->fresh(['currentInputVersion']));
        $action = app(ImportManualGenerationResult::class);

        $first = $action->execute($execution, $payload);
        $second = $action->execute($execution->fresh(), $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Document::query()->where('request_id', $request->id)->count());
        $this->assertSame(1, DocumentVersion::query()->where('document_id', $first->document_id)->count());
        $this->assertSame(1, AiExecution::query()->where('request_id', $request->id)->where('stage', 'audit')->count());
        $this->assertSame(1, RequestStateEvent::query()->where('request_id', $request->id)->where('reason', 'generation_result_imported')->count());
    }

    public function test_reintento_con_payload_distinto_se_rechaza(): void
    {
        [$request, $execution] = $this->waitingExecution();
        $payload = $this->generatedDraftFor($request->fresh(['currentInputVersion']));
        app(ImportManualGenerationResult::class)->execute($execution, $payload);
        $payload['title'] = 'Otro resultado distinto';

        try {
            app(ImportManualGenerationResult::class)->execute($execution->fresh(), $payload);
            $this->fail('Un segundo resultado diferente no debe reemplazar historial.');
        } catch (AiPipelineException $e) {
            $this->assertSame('AI_GENERATION_RESULT_IDEMPOTENCY_CONFLICT', $e->errorCode);
        }

        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_draft_invalido_no_crea_documento_ni_avanza_estado(): void
    {
        [$request, $execution] = $this->waitingExecution();
        $payload = $this->generatedDraftFor($request->fresh(['currentInputVersion']));
        unset($payload['sessions']);

        try {
            app(ImportManualGenerationResult::class)->execute($execution, $payload);
            $this->fail('Un GeneratedPlanDraftV1 inválido debe ser rechazado.');
        } catch (AiContractException) {
            $this->assertTrue(true);
        }

        $this->assertSame(PlanningRequestStatus::GENERACION_IA, $request->fresh()->status);
        $this->assertSame(AiExecutionStatus::WaitingManual, $execution->fresh()->status);
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame(0, AiExecution::query()->where('request_id', $request->id)->where('stage', 'audit')->count());
    }

    public function test_referencia_curricular_fuera_del_snapshot_no_persiste_resultado(): void
    {
        [$request, $execution] = $this->waitingExecution();
        $payload = $this->generatedDraftFor($request->fresh(['currentInputVersion']));
        $payload['sessions'][0]['pda_codes'] = ['PDA-NO-AUTORIZADO'];

        try {
            app(ImportManualGenerationResult::class)->execute($execution, $payload);
            $this->fail('Un PDA externo al snapshot debe ser rechazado.');
        } catch (AiContractException $e) {
            $this->assertStringContainsString('GENERATED_PDA_REFERENCE_NOT_ALLOWED', $e->getMessage());
        }

        $this->assertDatabaseCount('documents', 0);
        $this->assertSame(AiExecutionStatus::WaitingManual, $execution->fresh()->status);
    }

    public function test_sin_prompt_activo_de_auditoria_no_consume_nuevo_derecho_ni_persiste_version(): void
    {
        [$request, $execution] = $this->waitingExecution(withAuditPrompt: false);
        $payload = $this->generatedDraftFor($request->fresh(['currentInputVersion']));

        try {
            app(ImportManualGenerationResult::class)->execute($execution, $payload);
            $this->fail('No debe promover generación si no puede congelar la siguiente auditoría.');
        } catch (AiPipelineException $e) {
            $this->assertSame('AI_AUDIT_ACTIVE_PROMPT_MISSING', $e->errorCode);
        }

        $this->assertSame(PlanningRequestStatus::GENERACION_IA, $request->fresh()->status);
        $this->assertSame(AiExecutionStatus::WaitingManual, $execution->fresh()->status);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_execution_pending_sin_paquete_no_admite_resultado_manual(): void
    {
        config(['ai.mode' => 'manual']);
        Storage::fake('private');
        $request = $this->readyRequest();
        $this->publishGenerationPrompt();
        $this->publishAuditPrompt();
        $execution = app(DispatchPlanningGeneration::class)->execute($request);
        $payload = $this->generatedDraftFor($request->fresh(['currentInputVersion']));

        try {
            app(ImportManualGenerationResult::class)->execute($execution, $payload);
            $this->fail('Debe existir paquete manual preparado antes de importar respuesta.');
        } catch (AiPipelineException $e) {
            $this->assertSame('AI_GENERATION_RESULT_NOT_WAITING_MANUAL', $e->errorCode);
        }

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_metadata_incompleta_o_costo_sin_moneda_se_rechaza_antes_de_persistir(): void
    {
        [$request, $execution] = $this->waitingExecution();
        $payload = $this->generatedDraftFor($request->fresh(['currentInputVersion']));

        foreach ([
            ['provider', null, null, null, 'AI_GENERATION_PROVIDER_MODEL_PAIR_REQUIRED'],
            [null, null, '0.01', null, 'AI_GENERATION_COST_CURRENCY_INVALID'],
        ] as [$provider, $model, $cost, $currency, $code]) {
            try {
                app(ImportManualGenerationResult::class)->execute($execution, $payload, $provider, $model, $cost, $currency);
                $this->fail('Metadata incompleta debe fallar.');
            } catch (AiPipelineException $e) {
                $this->assertSame($code, $e->errorCode);
            }
        }

        $this->assertDatabaseCount('documents', 0);
        $this->assertSame(AiExecutionStatus::WaitingManual, $execution->fresh()->status);
    }

    public function test_human_review_sigue_reservada_despues_de_importar_generacion(): void
    {
        [$request, $execution] = $this->waitingExecution([
            'human_review_required' => true,
            'human_review_limit' => 8,
        ]);
        $payload = $this->generatedDraftFor($request->fresh(['currentInputVersion']));

        app(ImportManualGenerationResult::class)->execute($execution, $payload);

        $human = UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::HumanReview->value)
            ->sole();
        $this->assertSame(UsageReservationStatus::Reserved, $human->status);
        $this->assertNull($human->consumed_at);
    }

    public function test_comando_importa_archivo_json_y_deja_version_validada(): void
    {
        [$request, $execution] = $this->waitingExecution();
        $payload = $this->generatedDraftFor($request->fresh(['currentInputVersion']));
        $path = storage_path('app/manual-result-test.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        try {
            $exit = Artisan::call('ai:import-generation-result', [
                'execution' => $execution->id,
                'file' => $path,
            ]);
            $this->assertSame(0, $exit, Artisan::output());
            $this->assertDatabaseHas('document_versions', ['ai_execution_id' => $execution->id, 'status' => 'validated']);
            $this->assertSame(PlanningRequestStatus::AUDITORIA_IA, $request->fresh()->status);
        } finally {
            @unlink($path);
        }
    }
}
