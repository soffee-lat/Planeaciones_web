<?php

namespace Tests\Feature\Documents;

use App\Actions\AI\RouteAuditResult;
use App\Actions\Documents\DispatchDocumentRendering;
use App\Actions\Documents\ProcessDocumentRenderRun;
use App\Enums\PlanningRequestStatus;
use App\Models\PlanningRequest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDOException;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Feature\PedagogyTestCase;

class DocumentRenderingIntegrityTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;

    /** Real commits are required so deferred PostgreSQL constraints fire. */
    public function refreshDatabase(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
    }

    public function test_bd_rechaza_generando_documento_sin_run_de_render(): void
    {
        $scene = $this->approvedScene();

        try {
            DB::transaction(function () use ($scene): void {
                DB::table('planning_requests')->where('id', $scene['request']->id)->update([
                    'status' => PlanningRequestStatus::GENERANDO_DOCUMENTO->value,
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_at' => now(),
                ]);
                DB::table('request_state_events')->insert([
                    'request_id' => $scene['request']->id,
                    'from_status' => PlanningRequestStatus::APROBADA->value,
                    'to_status' => PlanningRequestStatus::GENERANDO_DOCUMENTO->value,
                    'actor_id' => null,
                    'actor_type' => 'system',
                    'reason' => 'document_render_dispatched',
                    'correlation_id' => '92929292-9292-4292-8292-929292929292',
                    'created_at' => now(),
                ]);
            });
            $this->fail('Se esperaba DOCUMENT_RENDER_RUN_REQUIRED.');
        } catch (QueryException|PDOException $e) {
            $this->assertStringContainsString('DOCUMENT_RENDER_RUN_REQUIRED', $e->getMessage());
        }
    }

    public function test_bd_rechaza_lista_para_entregar_sin_archivos_reales(): void
    {
        Queue::fake();
        $scene = $this->approvedScene();
        $run = app(DispatchDocumentRendering::class)->execute(
            $scene['request'],
            '93939393-9393-4393-8393-939393939393',
        );

        try {
            DB::transaction(function () use ($scene, $run): void {
                DB::table('document_render_runs')->where('id', $run->id)->update([
                    'status' => 'running',
                    'attempts' => 1,
                    'started_at' => now(),
                    'finished_at' => null,
                    'last_error_code' => null,
                    'updated_at' => now(),
                ]);
                DB::table('document_render_runs')->where('id', $run->id)->update([
                    'status' => 'succeeded',
                    'manifest' => json_encode([
                        'schema_version' => 'document_render_manifest_v1',
                        'request_id' => $scene['request']->id,
                        'version_id' => $scene['version']->id,
                        'format_version_id' => $run->format_version_id,
                        'renderer_version' => $run->renderer_version,
                        'source_content_hash' => $scene['version']->content_hash,
                        'outputs' => [
                            ['format' => 'docx', 'file_id' => 900001, 'sha256' => str_repeat('a', 64), 'size_bytes' => 1000, 'mime' => 'application/x-fake', 'path' => 'fake.docx'],
                            ['format' => 'pdf', 'file_id' => 900002, 'sha256' => str_repeat('b', 64), 'size_bytes' => 1000, 'mime' => 'application/pdf', 'path' => 'fake.pdf'],
                        ],
                    ], JSON_THROW_ON_ERROR),
                    'finished_at' => now(),
                    'last_error_code' => null,
                    'updated_at' => now(),
                ]);
                DB::table('planning_requests')->where('id', $scene['request']->id)->update([
                    'status' => PlanningRequestStatus::LISTA_PARA_ENTREGAR->value,
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_at' => now(),
                ]);
                DB::table('request_state_events')->insert([
                    'request_id' => $scene['request']->id,
                    'from_status' => PlanningRequestStatus::GENERANDO_DOCUMENTO->value,
                    'to_status' => PlanningRequestStatus::LISTA_PARA_ENTREGAR->value,
                    'actor_id' => null,
                    'actor_type' => 'system',
                    'reason' => 'document_render_succeeded',
                    'correlation_id' => $run->correlation_id,
                    'created_at' => now(),
                ]);
            });
            $this->fail('Se esperaba DOCUMENT_RENDER_OUTPUT_FILES_REQUIRED.');
        } catch (QueryException|PDOException $e) {
            $this->assertStringContainsString('DOCUMENT_RENDER_OUTPUT_FILES_REQUIRED', $e->getMessage());
        }
    }

    public function test_bd_congela_identidad_del_run_de_render(): void
    {
        Queue::fake();
        $scene = $this->approvedScene();
        $run = app(DispatchDocumentRendering::class)->execute($scene['request']);

        try {
            DB::table('document_render_runs')->where('id', $run->id)->update([
                'operation_key' => 'forged:operation:key',
                'updated_at' => now(),
            ]);
            $this->fail('Se esperaba DOCUMENT_RENDER_IDENTITY_IMMUTABLE.');
        } catch (QueryException|PDOException $e) {
            $this->assertStringContainsString('DOCUMENT_RENDER_IDENTITY_IMMUTABLE', $e->getMessage());
        }
    }

    public function test_bd_congela_run_exitoso(): void
    {
        Queue::fake();
        $scene = $this->approvedScene();
        $run = app(DispatchDocumentRendering::class)->execute($scene['request']);
        $run = app(ProcessDocumentRenderRun::class)->execute($run);

        try {
            DB::table('document_render_runs')->where('id', $run->id)->update([
                'attempts' => $run->attempts + 1,
                'updated_at' => now(),
            ]);
            $this->fail('Se esperaba DOCUMENT_RENDER_SUCCEEDED_IMMUTABLE.');
        } catch (QueryException|PDOException $e) {
            $this->assertStringContainsString('DOCUMENT_RENDER_SUCCEEDED_IMMUTABLE', $e->getMessage());
        }
    }

    /** @return array{request:PlanningRequest,version:\App\Models\DocumentVersion} */
    private function approvedScene(): array
    {
        config([
            'documents.disk' => 'private',
            'documents.prefix' => 'documents/integrity-test',
            'documents.queue' => 'documents',
        ]);
        $scene = $this->succeededAuditScenario(true);
        $request = app(RouteAuditResult::class)->execute($scene['audit']->fresh());
        $this->assertSame(PlanningRequestStatus::APROBADA, $request->status);

        return ['request' => $request, 'version' => $scene['version']->fresh(['document'])];
    }
}
