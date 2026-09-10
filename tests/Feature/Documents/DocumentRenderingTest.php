<?php

namespace Tests\Feature\Documents;

use App\Actions\AI\RouteAuditResult;
use App\Actions\Documents\DispatchDocumentRendering;
use App\Actions\Documents\ProcessDocumentRenderRun;
use App\Enums\DocumentRenderStatus;
use App\Enums\FileCategory;
use App\Enums\FileScanStatus;
use App\Enums\PlanningRequestStatus;
use App\Exceptions\DocumentRenderException;
use App\Jobs\RenderPlanningDocument;
use App\Models\DocumentRenderRun;
use App\Models\DocumentVersionFile;
use App\Models\PlanningRequest;
use App\Models\StoredFile;
use App\Services\Documents\DocumentRendererRegistry;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesInstitutionalFormatScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Feature\PedagogyTestCase;

class DocumentRenderingTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesInstitutionalFormatScenario;
    use CreatesManualAiPipelineScenario;

    public function test_despacho_aprobado_crea_run_y_pasa_a_generando_documento(): void
    {
        Queue::fake();
        $scene = $this->approvedScene();
        $run = app(DispatchDocumentRendering::class)->execute($scene['request'], '91919191-9191-4191-8191-919191919191');
        $this->assertSame(DocumentRenderStatus::Pending, $run->status);
        $this->assertSame(PlanningRequestStatus::GENERANDO_DOCUMENTO, $run->request->status);
        $this->assertSame($scene['version']->id, $run->version_id);
        $this->assertSame('standard-v1.0.0', $run->renderer_version);
        $this->assertDatabaseHas('request_state_events', [
            'request_id' => $scene['request']->id,
            'from_status' => PlanningRequestStatus::APROBADA->value,
            'to_status' => PlanningRequestStatus::GENERANDO_DOCUMENTO->value,
            'reason' => 'document_render_dispatched',
            'correlation_id' => '91919191-9191-4191-8191-919191919191',
        ]);
        Queue::assertPushed(RenderPlanningDocument::class, fn (RenderPlanningDocument $job): bool => $job->renderRunId === $run->id && $job->queue === 'documents');
    }

    public function test_despacho_es_idempotente_mientras_el_run_esta_pendiente(): void
    {
        Queue::fake();
        $scene = $this->approvedScene();
        $first = app(DispatchDocumentRendering::class)->execute($scene['request']);
        $second = app(DispatchDocumentRendering::class)->execute($scene['request']->fresh());
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DocumentRenderRun::query()->where('request_id', $scene['request']->id)->count());
        Queue::assertPushed(RenderPlanningDocument::class, 1);
    }

    public function test_procesamiento_genera_docx_pdf_privados_y_deja_lista_para_entregar(): void
    {
        Queue::fake();
        $scene = $this->approvedScene();
        $run = app(DispatchDocumentRendering::class)->execute($scene['request']);
        $done = app(ProcessDocumentRenderRun::class)->execute($run);
        $this->assertSame(DocumentRenderStatus::Succeeded, $done->status);
        $this->assertSame(PlanningRequestStatus::LISTA_PARA_ENTREGAR, $done->request->status);
        $this->assertSame('document_render_manifest_v1', $done->manifest['schema_version']);
        $this->assertSame($scene['version']->content_hash, $done->manifest['source_content_hash']);
        $this->assertSame(['docx', 'pdf'], array_column($done->manifest['outputs'], 'format'));
        $this->assertSame(2, StoredFile::query()->where('request_id', $scene['request']->id)->where('category', FileCategory::Result->value)->count());
        $this->assertSame(2, DocumentVersionFile::query()->where('version_id', $scene['version']->id)->count());
        foreach ($done->manifest['outputs'] as $output) {
            Storage::disk('private')->assertExists($output['path']);
            $this->assertSame($output['sha256'], hash('sha256', Storage::disk('private')->get($output['path'])));
        }
        $docx = collect($done->manifest['outputs'])->firstWhere('format', 'docx');
        $pdf = collect($done->manifest['outputs'])->firstWhere('format', 'pdf');
        $docxBytes = Storage::disk('private')->get($docx['path']);
        $pdfBytes = Storage::disk('private')->get($pdf['path']);
        $this->assertStringStartsWith("PK\x03\x04", $docxBytes);
        $this->assertStringContainsString('[Content_Types].xml', $docxBytes);
        $this->assertStringContainsString('Planeación DEMO', $docxBytes);
        $this->assertStringStartsWith('%PDF-1.4', $pdfBytes);
        $this->assertStringEndsWith("%%EOF\n", $pdfBytes);
        $this->assertDatabaseHas('request_state_events', [
            'request_id' => $scene['request']->id,
            'from_status' => PlanningRequestStatus::GENERANDO_DOCUMENTO->value,
            'to_status' => PlanningRequestStatus::LISTA_PARA_ENTREGAR->value,
            'reason' => 'document_render_succeeded',
        ]);
    }

    public function test_renderer_estandar_es_determinista_para_misma_version(): void
    {
        Queue::fake();
        $scene = $this->approvedScene();
        $run = app(DispatchDocumentRendering::class)->execute($scene['request']);
        $renderer = app(DocumentRendererRegistry::class);
        $first = $renderer->render($run->version, $run->formatVersion);
        $second = $renderer->render($run->version, $run->formatVersion);
        $this->assertSame($first[0]->sha256(), $second[0]->sha256());
        $this->assertSame($first[1]->sha256(), $second[1]->sha256());
        $this->assertSame($first[0]->bytes, $second[0]->bytes);
        $this->assertSame($first[1]->bytes, $second[1]->bytes);
    }

    public function test_reprocesar_run_exitoso_no_duplica_archivos_ni_intentos(): void
    {
        Queue::fake();
        $scene = $this->approvedScene();
        $run = app(DispatchDocumentRendering::class)->execute($scene['request']);
        $first = app(ProcessDocumentRenderRun::class)->execute($run);
        $second = app(ProcessDocumentRenderRun::class)->execute($first);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $second->attempts);
        $this->assertSame(2, StoredFile::query()->where('request_id', $scene['request']->id)->where('category', FileCategory::Result->value)->count());
        $this->assertSame(2, DocumentVersionFile::query()->where('version_id', $scene['version']->id)->count());
    }

    public function test_formato_institucional_publicado_usa_renderer_explicito(): void
    {
        Queue::fake();
        $scene = $this->approvedScene();
        $published = $this->publishedInstitutionalFormat($scene['request']->owner_id);
        $scene['request']->update(['format_version_id' => $published->id]);
        Queue::fake();
        $run = app(DispatchDocumentRendering::class)->execute($scene['request']->fresh());
        $this->assertSame('institutional-v1.0.0', $run->renderer_version);
        $done = app(ProcessDocumentRenderRun::class)->execute($run);
        $this->assertSame(DocumentRenderStatus::Succeeded, $done->status);
        $this->assertSame(PlanningRequestStatus::LISTA_PARA_ENTREGAR, $done->request->status);
        $docx = collect($done->manifest['outputs'])->firstWhere('format', 'docx');
        $docxBytes = Storage::disk('private')->get($docx['path']);
        $this->assertStringContainsString('FORMATO INSTITUCIONAL DEMO', $docxBytes);
        $this->assertStringContainsString('Planeación DEMO', $docxBytes);
        $this->assertStringNotContainsString('{{TITLE}}', $docxBytes);
    }

    public function test_fallo_de_storage_conserva_generando_documento_y_abre_bloque_recuperable(): void
    {
        Queue::fake();
        $scene = $this->approvedScene();
        $run = app(DispatchDocumentRendering::class)->execute($scene['request']);
        config(['documents.disk' => 'disk-inexistente']);
        try {
            app(ProcessDocumentRenderRun::class)->execute($run);
            $this->fail('Se esperaba fallo recuperable de render.');
        } catch (DocumentRenderException $e) {
            $this->assertSame('DOCUMENT_RENDER_PROCESSING_FAILED', $e->errorCode);
        }
        $run->refresh();
        $this->assertSame(DocumentRenderStatus::Failed, $run->status);
        $this->assertSame('DOCUMENT_RENDER_PROCESSING_FAILED', $run->last_error_code);
        $this->assertSame(PlanningRequestStatus::GENERANDO_DOCUMENTO, $scene['request']->fresh()->status);
        $this->assertDatabaseHas('request_blocks', [
            'request_id' => $scene['request']->id,
            'code' => 'document_render_failed',
            'stage' => 'document_render',
            'resolved_at' => null,
        ]);
    }

    public function test_run_fallido_puede_redespacharse_sin_crear_otro_run(): void
    {
        Queue::fake();
        $scene = $this->approvedScene();
        $run = app(DispatchDocumentRendering::class)->execute($scene['request']);
        config(['documents.disk' => 'disk-inexistente']);
        try { app(ProcessDocumentRenderRun::class)->execute($run); } catch (DocumentRenderException) {}
        config(['documents.disk' => 'private']);
        Queue::fake();
        $retried = app(DispatchDocumentRendering::class)->execute($scene['request']->fresh());
        $this->assertSame($run->id, $retried->id);
        $this->assertSame(1, DocumentRenderRun::query()->where('request_id', $scene['request']->id)->count());
        Queue::assertPushed(RenderPlanningDocument::class, 1);
    }

    public function test_archivos_resultado_quedan_marcados_clean_por_ser_generados_internamente(): void
    {
        Queue::fake();
        $scene = $this->approvedScene();
        $run = app(DispatchDocumentRendering::class)->execute($scene['request']);
        app(ProcessDocumentRenderRun::class)->execute($run);
        $files = StoredFile::query()->where('request_id', $scene['request']->id)->where('category', FileCategory::Result->value)->get();
        $this->assertCount(2, $files);
        foreach ($files as $file) {
            $this->assertSame(FileScanStatus::Clean, $file->scan_status);
            $this->assertSame('private', $file->disk);
            $this->assertNull($file->uploaded_by);
        }
    }

    private function approvedScene(): array
    {
        config([
            'documents.disk' => 'private',
            'documents.prefix' => 'documents/test',
            'documents.queue' => 'documents',
        ]);
        $scene = $this->succeededAuditScenario(true);
        $request = app(RouteAuditResult::class)->execute($scene['audit']->fresh());
        $this->assertSame(PlanningRequestStatus::APROBADA, $request->status);
        return ['request' => $request, 'version' => $scene['version']->fresh(['document'])];
    }
}
