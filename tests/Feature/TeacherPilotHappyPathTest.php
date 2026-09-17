<?php

namespace Tests\Feature;

use App\Actions\AI\DispatchPlanningGeneration;
use App\Actions\AI\ImportManualAuditResult;
use App\Actions\AI\ImportManualGenerationResult;
use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\AI\RouteAuditResult;
use App\Actions\Documents\DispatchDocumentRendering;
use App\Actions\Documents\ProcessDocumentRenderRun;
use App\Actions\Documents\PublishPlanningDelivery;
use App\Actions\Planning\StartPlanningExperiment;
use App\Enums\AiExecutionStage;
use App\Enums\DocumentRenderStatus;
use App\Enums\PlanningRequestStatus;
use App\Enums\UsageReservationStatus;
use App\Filament\App\Resources\PlanningRequests\PlanningRequestResource;
use App\Models\AiExecution;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsGeneratedPlanDraft;

class TeacherPilotHappyPathTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;

    public function test_docente_piloto_completa_planeacion_desde_mapa_curricular_hasta_entrega_estandar(): void
    {
        config([
            'ai.mode' => 'manual',
            'ai.manual.disk' => 'private',
            'ai.manual.prefix' => 'ai/teacher-pilot-happy-path',
            'documents.disk' => 'private',
            'documents.prefix' => 'documents/teacher-pilot-happy-path',
            'documents.queue' => 'documents',
            'documents.result_retention_days' => 365,
        ]);
        Storage::fake('private');
        Queue::fake();

        $scene = $this->seedFullTeacher();
        $teacher = $scene['user']->refresh();

        $this->artisan('validation:grant-pilot-access', [
            'email' => $teacher->email,
            '--days' => 30,
        ])->assertSuccessful();

        $this->actingAs($teacher);

        $this->get('/app/nueva-planeacion')
            ->assertOk()
            ->assertSee('Prepara tu planeación')
            ->assertDontSee('Formato de salida');

        $request = app(StartPlanningExperiment::class)->execute(
            $teacher,
            $scene['group']->id,
            '2026-09-21',
            '2026-09-25',
            'Comunicación oral y escrita',
            'Expresa ideas y recupera información relevante en actividades de comunicación.',
        );

        $this->assertNull($request->format_version_id);
        $this->assertSame(PlanningRequestStatus::BORRADOR, $request->status);

        $this->get(route('planning.curriculum-map', $request))->assertOk();
        $this->post(route('planning.curriculum-map.accept-all', $request))->assertRedirect();

        $this->post(route('planning.curriculum-map.confirm', $request))
            ->assertRedirect(PlanningRequestResource::getUrl('view', ['record' => $request->id]));

        $request = $request->fresh(['currentInputVersion']);
        $this->assertSame(PlanningRequestStatus::LISTA_PARA_PROCESAR, $request->status);
        $this->assertNotNull($request->commercial_authorized_at);
        $this->assertNotNull($request->currentInputVersion);
        $this->assertNull($request->format_version_id);
        $this->assertSame(1, $request->usageReservations()->count());

        $generation = app(DispatchPlanningGeneration::class)->execute(
            $request,
            '51515151-5151-4151-8151-515151515151',
        );
        $this->assertNull($generation->format_version_id);

        app(ProcessOutboxEvent::class)->execute(
            OutboxEvent::query()
                ->where('event_key', 'ai-execution:' . $generation->id . ':generation-dispatch')
                ->sole(),
        );

        $version = app(ImportManualGenerationResult::class)->execute(
            $generation->fresh(),
            $this->generatedDraftFor($request->fresh(['currentInputVersion'])),
        );

        $audit = AiExecution::query()
            ->where('request_id', $request->id)
            ->where('stage', AiExecutionStage::Audit->value)
            ->sole();

        app(ProcessOutboxEvent::class)->execute(
            OutboxEvent::query()
                ->where('event_key', 'ai-execution:' . $audit->id . ':audit-dispatch')
                ->sole(),
        );

        app(ImportManualAuditResult::class)->execute(
            $audit->fresh(),
            [
                'schema_version' => 'audit_result_v1',
                'passed' => true,
                'findings' => [],
            ],
        );

        $approved = app(RouteAuditResult::class)->execute($audit->fresh());

        $this->assertSame(PlanningRequestStatus::APROBADA, $approved->status);
        $this->assertNull($approved->format_version_id);
        $this->assertSame($version->id, $approved->document()->firstOrFail()->current_version_id);
        $this->assertSame(
            UsageReservationStatus::Consumed,
            $approved->usageReservations()->firstOrFail()->status,
        );

        $run = app(DispatchDocumentRendering::class)->execute($approved);
        $this->assertSame('standard-v1', $run->formatVersion->renderer);
        $this->assertSame('standard-v1.0.0', $run->renderer_version);

        $done = app(ProcessDocumentRenderRun::class)->execute($run);
        $this->assertSame(DocumentRenderStatus::Succeeded, $done->status);
        $this->assertSame(PlanningRequestStatus::LISTA_PARA_ENTREGAR, $done->request->status);

        $delivery = app(PublishPlanningDelivery::class)->execute(
            $done->request->fresh(),
            $teacher,
        );

        $this->assertSame(PlanningRequestStatus::ENTREGADA, $done->request->fresh()->status);
        $this->assertCount(2, $delivery->files);
        $this->assertSame(
            ['docx', 'pdf'],
            $delivery->files->pluck('pivot.output_format')->sort()->values()->all(),
        );

        $this->get(PlanningRequestResource::getUrl('view', ['record' => $request->id]))
            ->assertOk()
            ->assertSee('Planeación lista')
            ->assertSee('Descargar DOCX')
            ->assertSee('Descargar PDF');
    }
}
