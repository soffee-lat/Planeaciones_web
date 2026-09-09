<?php

namespace Tests\Feature\AI;

use App\Actions\AI\DispatchPlanningGeneration;
use App\Actions\AI\ImportManualGenerationResult;
use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\AI\PublishPromptVersion;
use App\Enums\PlanningRequestStatus;
use App\Enums\PromptCategory;
use App\Models\AiExecution;
use App\Models\DocumentVersion;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Feature\PedagogyTestCase;

class PlanningGenerationResultIntegrityTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;

    /** Commits reales para ejecutar los constraint triggers diferidos de 4C. */
    public function refreshDatabase(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
        $this->beforeApplicationDestroyed(function (): void {
            Artisan::call('migrate:rollback', ['--force' => true]);
            RefreshDatabaseState::$migrated = false;
        });
    }

    private function publishPrompt(string $key, PromptCategory $category): PromptVersion
    {
        $template = PromptTemplate::factory()->create([
            'key' => $key,
            'category' => $category->value,
        ]);
        $attributes = [
            'template_id' => $template->id,
        ];
        if ($category === PromptCategory::Audit) {
            $attributes += [
                'body' => 'CANONICAL={{canonical_plan}} OUTPUT={{output_schema}}',
                'allowed_variables' => ['canonical_plan', 'output_schema'],
                'output_schema' => json_decode(file_get_contents(resource_path('schemas/ai/audit_result_v1.schema.json')), true, 512, JSON_THROW_ON_ERROR),
                'schema_version' => 'audit_result_v1',
            ];
        } else {
            $attributes += [
                'body' => 'INPUT={{input_snapshot}} OUTPUT={{output_schema}}',
                'allowed_variables' => ['input_snapshot', 'output_schema'],
            ];
        }
        $version = PromptVersion::factory()->create($attributes);

        return app(PublishPromptVersion::class)->execute($this->admin(), $version);
    }

    /** @return array{0:PlanningRequest,1:AiExecution} */
    private function waitingExecution(): array
    {
        config([
            'ai.mode' => 'manual',
            'ai.manual.disk' => 'private',
            'ai.manual.prefix' => 'ai/result-integrity',
        ]);
        Storage::fake('private');
        $request = $this->draft();
        $this->period($request);
        $request = $this->authorize($this->confirm($request));
        $this->publishPrompt('planning.generation', PromptCategory::Generation);
        $this->publishPrompt('planning.audit', PromptCategory::Audit);
        $execution = app(DispatchPlanningGeneration::class)->execute(
            $request,
            '77777777-7777-4777-8777-777777777777',
        );
        app(ProcessOutboxEvent::class)->execute(OutboxEvent::query()->where('aggregate_id', $request->id)->sole());

        return [$request->fresh(), $execution->fresh()];
    }

    public function test_bd_rechaza_auditoria_directa_sin_resultado_documental(): void
    {
        [$request] = $this->waitingExecution();
        $statementsCompleted = false;

        try {
            DB::transaction(function () use ($request, &$statementsCompleted): void {
                DB::table('planning_requests')->where('id', $request->id)->update([
                    'status' => PlanningRequestStatus::AUDITORIA_IA->value,
                ]);
                $statementsCompleted = true;
            });
            $this->fail('AUDITORIA_IA requiere generación importada y DocumentVersion.');
        } catch (\PDOException $error) {
            $this->assertTrue($statementsCompleted);
            $this->assertStringContainsString('AI_GENERATION_RESULT_REQUIRED', $error->getMessage());
        }

        $this->assertSame(PlanningRequestStatus::GENERACION_IA, $request->fresh()->status);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_import_normal_satisface_invariantes_en_commit_real(): void
    {
        [$request, $execution] = $this->waitingExecution();
        $payload = $this->generatedDraftFor($request->fresh(['currentInputVersion']));

        $version = app(ImportManualGenerationResult::class)->execute($execution, $payload);

        $this->assertSame(PlanningRequestStatus::AUDITORIA_IA, $request->fresh()->status);
        $this->assertDatabaseHas('document_versions', [
            'id' => $version->id,
            'ai_execution_id' => $execution->id,
            'status' => 'validated',
        ]);
        $this->assertDatabaseHas('ai_executions', [
            'id' => $execution->id,
            'status' => 'succeeded',
            'resulting_version_id' => $version->id,
        ]);
        $this->assertSame(1, AiExecution::query()->where('request_id', $request->id)->where('stage', 'audit')->count());
    }

    public function test_bd_rechaza_marcar_generation_succeeded_sin_version(): void
    {
        [, $execution] = $this->waitingExecution();

        try {
            DB::table('ai_executions')->where('id', $execution->id)->update([
                'status' => 'succeeded',
                'finished_at' => now(),
            ]);
            $this->fail('Generation succeeded requiere resulting_version_id coherente.');
        } catch (\PDOException $error) {
            $this->assertStringContainsString('AI_EXECUTION_RESULT_REQUIRED', $error->getMessage());
        }

        $this->assertSame('waiting_manual', $execution->fresh()->status->value);
    }

    public function test_bd_rechaza_document_version_con_source_request_adulterado(): void
    {
        [$request, $execution] = $this->waitingExecution();
        $payload = $this->generatedDraftFor($request->fresh(['currentInputVersion']));
        $version = app(ImportManualGenerationResult::class)->execute($execution, $payload);
        $bad = $version->content;
        $bad['source']['planning_request_id'] = $request->id + 999;

        try {
            DB::table('document_versions')->insert([
                'document_id' => $version->document_id,
                'number' => 2,
                'parent_version_id' => $version->id,
                'input_revision' => $version->input_revision,
                'content' => json_encode($bad, JSON_THROW_ON_ERROR),
                'content_hash' => str_repeat('a', 64),
                'source_payload_hash' => str_repeat('b', 64),
                'created_by' => null,
                'ai_execution_id' => null,
                'status' => 'validated',
                'created_at' => now(),
            ]);
            $this->fail('Canonical source no puede apuntar a otra solicitud.');
        } catch (\PDOException $error) {
            $this->assertStringContainsString('DOCUMENT_VERSION_CANONICAL_SOURCE_MISMATCH', $error->getMessage());
        }
    }

    public function test_bd_protege_version_documental_y_resultado_exitoso_contra_mutacion(): void
    {
        [$request, $execution] = $this->waitingExecution();
        $payload = $this->generatedDraftFor($request->fresh(['currentInputVersion']));
        $version = app(ImportManualGenerationResult::class)->execute($execution, $payload);

        try {
            DB::table('document_versions')->where('id', $version->id)->update(['status' => 'approved']);
            $this->fail('DocumentVersion debe ser append-only.');
        } catch (\PDOException $error) {
            $this->assertStringContainsString('DOCUMENT_VERSION_IMMUTABLE', $error->getMessage());
        }

        try {
            DB::table('document_versions')->where('id', $version->id)->delete();
            $this->fail('DocumentVersion no debe borrarse.');
        } catch (\PDOException $error) {
            $this->assertStringContainsString('DOCUMENT_VERSION_IMMUTABLE', $error->getMessage());
        }

        DB::table('ai_executions')->where('id', $execution->id)->update([
            'provider' => 'manual-reconciled',
            'model' => 'operator-v1',
            'actual_cost' => '0.01000000',
            'cost_currency' => 'MXN',
        ]);

        $reconciled = $execution->fresh();
        $this->assertSame('manual-reconciled', $reconciled->provider);
        $this->assertSame('operator-v1', $reconciled->model);
        $this->assertSame('0.01000000', $reconciled->actual_cost);
        $this->assertSame('MXN', $reconciled->cost_currency);

        try {
            DB::table('ai_executions')->where('id', $execution->id)->update([
                'provider' => 'tampered',
                'model' => 'tampered-v2',
            ]);
            $this->fail('Provider/model reconciliados deben quedar congelados.');
        } catch (\PDOException $error) {
            $this->assertStringContainsString('AI_EXECUTION_PROVIDER_MODEL_IMMUTABLE', $error->getMessage());
        }

        try {
            DB::table('ai_executions')->where('id', $execution->id)->update([
                'actual_cost' => '0.02000000',
                'cost_currency' => 'MXN',
            ]);
            $this->fail('Costo real reconciliado debe quedar congelado.');
        } catch (\PDOException $error) {
            $this->assertStringContainsString('AI_EXECUTION_ACTUAL_COST_IMMUTABLE', $error->getMessage());
        }
    }
}
