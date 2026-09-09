<?php

namespace Tests\Feature\AI;

use App\Actions\AI\DispatchPlanningGeneration;
use App\Actions\AI\ImportManualAuditResult;
use App\Actions\AI\ImportManualGenerationResult;
use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\AI\PublishPromptVersion;
use App\Actions\AI\RouteAuditResult;
use App\Enums\AiExecutionStage;
use App\Enums\OutboxEventType;
use App\Enums\PlanningRequestStatus;
use App\Enums\PromptCategory;
use App\Models\AiExecution;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Feature\PedagogyTestCase;

class PlanningAuditIntegrityTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;

    public function refreshDatabase(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
        $this->beforeApplicationDestroyed(function (): void {
            Artisan::call('migrate:rollback', ['--force' => true]);
            RefreshDatabaseState::$migrated = false;
        });
    }

    /** @return array{0:PlanningRequest,1:AiExecution} */
    private function auditExecution(bool $processPackage = true): array
    {
        config([
            'ai.mode' => 'manual',
            'ai.manual.disk' => 'private',
            'ai.manual.prefix' => 'ai/audit-integrity-test',
        ]);
        Storage::fake('private');
        $request = $this->draft();
        $this->period($request);
        $request = $this->authorize($this->confirm($request));
        $this->publishPrompts();

        $generation = app(DispatchPlanningGeneration::class)->execute($request, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        app(ProcessOutboxEvent::class)->execute(OutboxEvent::query()->where('type', OutboxEventType::PlanningGenerationRequested->value)->sole());
        app(ImportManualGenerationResult::class)->execute(
            $generation->fresh(),
            $this->generatedDraftFor($request->fresh(['currentInputVersion'])),
        );
        $audit = AiExecution::query()->where('request_id', $request->id)->where('stage', AiExecutionStage::Audit->value)->sole();
        if ($processPackage) {
            app(ProcessOutboxEvent::class)->execute(OutboxEvent::query()->where('type', OutboxEventType::PlanningAuditRequested->value)->sole());
        }

        return [$request->fresh(), $audit->fresh()];
    }

    public function test_bd_rechaza_audit_succeeded_sin_reporte(): void
    {
        [, $audit] = $this->auditExecution();

        try {
            DB::table('ai_executions')->where('id', $audit->id)->update([
                'status' => 'succeeded',
                'finished_at' => now(),
            ]);
            $this->fail('Audit succeeded requiere audit_report estructurado.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('AI_AUDIT_RESULT_REQUIRED', $e->getMessage());
        }

        $this->assertSame('waiting_manual', $audit->fresh()->status->value);
    }

    public function test_bd_rechaza_saltar_a_estado_posterior_con_audit_pendiente(): void
    {
        [$request] = $this->auditExecution(false);
        $statementsCompleted = false;

        try {
            DB::transaction(function () use ($request, &$statementsCompleted): void {
                DB::table('planning_requests')->where('id', $request->id)->update([
                    'status' => PlanningRequestStatus::APROBADA->value,
                ]);
                $statementsCompleted = true;
            });
            $this->fail('No debe saltarse AUDITORIA_IA con ejecución audit pendiente.');
        } catch (\PDOException $e) {
            $this->assertTrue($statementsCompleted);
            $this->assertStringContainsString('AI_AUDIT_SUCCEEDED_REQUIRED', $e->getMessage());
        }

        $this->assertSame(PlanningRequestStatus::AUDITORIA_IA, $request->fresh()->status);
    }

    public function test_bd_congela_audit_report_tras_exito(): void
    {
        [, $audit] = $this->auditExecution();
        app(ImportManualAuditResult::class)->execute($audit, [
            'schema_version' => 'audit_result_v1',
            'passed' => true,
            'findings' => [],
        ]);

        try {
            DB::table('ai_executions')->where('id', $audit->id)->update([
                'audit_report' => json_encode([
                    'schema_version' => 'audit_result_v1',
                    'passed' => false,
                    'findings' => [[
                        'code' => 'SCHEMA',
                        'severity' => 'critical',
                        'json_path' => '$',
                        'explanation' => 'Manipulado.',
                        'expected_correction' => 'No aplica.',
                    ]],
                ], JSON_THROW_ON_ERROR),
            ]);
            $this->fail('Un audit_report exitoso debe ser histórico e inmutable.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('AI_EXECUTION_SUCCEEDED_IMMUTABLE', $e->getMessage());
        }
    }

    public function test_import_normal_satisface_guard_y_ruteo_4e_crea_correccion_coherente(): void
    {
        [$request, $audit] = $this->auditExecution();
        app(ImportManualAuditResult::class)->execute($audit, [
            'schema_version' => 'audit_result_v1',
            'passed' => false,
            'findings' => [[
                'code' => 'LANGUAGE_QUALITY',
                'severity' => 'medium',
                'json_path' => '/sessions/0',
                'explanation' => 'La redacción necesita precisión.',
                'expected_correction' => 'Ajustar la redacción sin cambiar currículo.',
            ]],
        ]);

        app(RouteAuditResult::class)->execute($audit->fresh());

        $this->assertSame(PlanningRequestStatus::CORRECCION_IA, $request->fresh()->status);
        $this->assertDatabaseHas('ai_executions', [
            'request_id' => $request->id,
            'stage' => 'correction',
            'status' => 'pending',
        ]);
    }

    private function publishPrompts(): void
    {
        foreach ([PromptCategory::Generation, PromptCategory::Audit, PromptCategory::Correction] as $category) {
            $key = match ($category) {
                PromptCategory::Generation => 'planning.generation',
                PromptCategory::Audit => 'planning.audit',
                PromptCategory::Correction => 'planning.correction',
                default => throw new \LogicException('Prompt category no soportada en fixture.'),
            };
            $template = PromptTemplate::factory()->create(['key' => $key, 'category' => $category->value]);
            $attributes = match ($category) {
                PromptCategory::Generation => [
                    'template_id' => $template->id,
                    'body' => 'INPUT={{input_snapshot}} OUTPUT={{output_schema}}',
                    'allowed_variables' => ['input_snapshot', 'output_schema'],
                    'output_schema' => json_decode(file_get_contents(resource_path('schemas/ai/generated_plan_draft_v1.schema.json')), true, 512, JSON_THROW_ON_ERROR),
                    'schema_version' => 'generated_plan_draft_v1',
                ],
                PromptCategory::Audit => [
                    'template_id' => $template->id,
                    'body' => 'CANONICAL={{canonical_plan}} OUTPUT={{output_schema}}',
                    'allowed_variables' => ['canonical_plan', 'output_schema'],
                    'output_schema' => json_decode(file_get_contents(resource_path('schemas/ai/audit_result_v1.schema.json')), true, 512, JSON_THROW_ON_ERROR),
                    'schema_version' => 'audit_result_v1',
                ],
                PromptCategory::Correction => [
                    'template_id' => $template->id,
                    'body' => 'CANONICAL={{canonical_plan}} AUDIT={{audit_report}} SCOPE={{section_keys}} OUTPUT={{output_schema}}',
                    'allowed_variables' => ['canonical_plan', 'audit_report', 'section_keys', 'output_schema'],
                    'output_schema' => json_decode(file_get_contents(resource_path('schemas/ai/correction_result_v1.schema.json')), true, 512, JSON_THROW_ON_ERROR),
                    'schema_version' => 'correction_result_v1',
                ],
                default => [],
            };
            app(PublishPromptVersion::class)->execute($this->admin(), PromptVersion::factory()->create($attributes));
        }
    }
}
