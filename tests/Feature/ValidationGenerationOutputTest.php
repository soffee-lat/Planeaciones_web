<?php

namespace Tests\Feature;

use App\Actions\AI\DispatchPlanningGeneration;
use App\Actions\AI\ImportManualGenerationResult;
use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\Documents\PublishPlanningDelivery;
use App\Enums\ProductEventType;
use App\Models\OutboxEvent;
use App\Models\ProductEvent;
use App\Services\Analytics\ProductEventRecorder;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Concerns\CreatesRenderedPlanningScenario;

class ValidationGenerationOutputTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;
    use CreatesRenderedPlanningScenario;

    public function test_generacion_del_flujo_de_validacion_registra_plan_generated(): void
    {
        config([
            'ai.mode' => 'manual',
            'ai.manual.disk' => 'private',
            'ai.manual.prefix' => 'ai/validation-v3-test',
        ]);
        Storage::fake('private');

        $request = $this->draft();
        $this->period($request);
        $request = $this->authorize($this->confirm($request));
        $this->publishManualAiPrompts();

        app(ProductEventRecorder::class)->record(
            $request->owner,
            ProductEventType::PlanningStarted,
            request: $request,
            metadata: [
                'entry_surface' => 'curricular_validation_v1',
                'profile_reused' => true,
                'session_minutes_known' => true,
            ],
        );

        $execution = app(DispatchPlanningGeneration::class)->execute(
            $request,
            '93939393-9393-4939-8939-939393939393',
        );
        app(ProcessOutboxEvent::class)->execute(
            OutboxEvent::query()->where('event_key', 'ai-execution:' . $execution->id . ':generation-dispatch')->sole(),
        );

        $version = app(ImportManualGenerationResult::class)->execute(
            $execution->fresh(),
            $this->generatedDraftFor($request->fresh(['currentInputVersion'])),
        );

        $event = ProductEvent::query()
            ->where('planning_request_id', $request->id)
            ->where('event_type', ProductEventType::PlanGenerated->value)
            ->sole();

        $this->assertSame($version->id, (int) $event->metadata['document_version_id']);
        $this->assertSame('canonical_plan_v1', $event->metadata['renderer']);
    }

    public function test_descarga_docx_del_flujo_de_validacion_registra_evento(): void
    {
        $scene = $this->renderedPlanningScene('documents/validation-v3-download');
        $request = $scene['request'];

        app(ProductEventRecorder::class)->record(
            $request->owner,
            ProductEventType::PlanningStarted,
            request: $request,
            metadata: ['entry_surface' => 'curricular_validation_v1'],
        );

        $delivery = app(PublishPlanningDelivery::class)->execute($request);
        $docx = $delivery->files->first(fn ($file) => $file->pivot->output_format === 'docx');

        $this->actingAs($request->owner)
            ->get(route('planning-deliveries.download', ['delivery' => $delivery->id, 'file' => $docx->id]))
            ->assertOk();

        $event = ProductEvent::query()
            ->where('planning_request_id', $request->id)
            ->where('event_type', ProductEventType::DocxDownloaded->value)
            ->sole();

        $this->assertSame($delivery->id, (int) $event->metadata['delivery_id']);
        $this->assertSame($docx->id, (int) $event->metadata['file_id']);
        $this->assertSame($scene['run']->format_version_id, (int) $event->metadata['format_version_id']);
    }

    public function test_descargar_pdf_no_se_confunde_con_evento_docx(): void
    {
        $scene = $this->renderedPlanningScene('documents/validation-v3-pdf');
        $request = $scene['request'];

        app(ProductEventRecorder::class)->record(
            $request->owner,
            ProductEventType::PlanningStarted,
            request: $request,
            metadata: ['entry_surface' => 'curricular_validation_v1'],
        );

        $delivery = app(PublishPlanningDelivery::class)->execute($request);
        $pdf = $delivery->files->first(fn ($file) => $file->pivot->output_format === 'pdf');

        $this->actingAs($request->owner)
            ->get(route('planning-deliveries.download', ['delivery' => $delivery->id, 'file' => $pdf->id]))
            ->assertOk();

        $this->assertSame(0, ProductEvent::query()
            ->where('planning_request_id', $request->id)
            ->where('event_type', ProductEventType::DocxDownloaded->value)
            ->count());
    }

    public function test_vista_docente_muestra_preview_de_version_generada(): void
    {
        $scene = $this->renderedPlanningScene('documents/validation-v3-preview');

        $this->actingAs($scene['request']->owner)
            ->get('/app/planning-requests/' . $scene['request']->id)
            ->assertOk()
            ->assertSee('Vista previa de la planeación')
            ->assertSee('Sesiones (2)');
    }

    public function test_solicitud_lista_para_procesar_expone_accion_de_generacion(): void
    {
        $request = $this->draft();
        $this->period($request);
        $request = $this->authorize($this->confirm($request));

        $this->actingAs($request->owner)
            ->get('/app/planning-requests/' . $request->id)
            ->assertOk()
            ->assertSee('Generar planeación');
    }
}
