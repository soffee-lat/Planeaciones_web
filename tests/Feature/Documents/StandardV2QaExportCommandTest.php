<?php

namespace Tests\Feature\Documents;

use App\Actions\AI\RouteAuditResult;
use App\Enums\PlanningRequestStatus;
use App\Models\AiExecution;
use App\Models\DocumentRenderRun;
use App\Models\PlanningDelivery;
use App\Models\RequestInputVersion;
use App\Models\UsageReservation;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Feature\PedagogyTestCase;

class StandardV2QaExportCommandTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;

    public function test_exporta_docx_y_pdf_para_qa_sin_mutar_la_planeacion_aprobada(): void
    {
        $scene = $this->succeededAuditScenario(true);
        $request = app(RouteAuditResult::class)->execute($scene['audit']->fresh());
        $version = $scene['version']->fresh();

        $this->assertSame(PlanningRequestStatus::APROBADA, $request->status);
        $this->assertNull($request->format_version_id);

        $before = [
            'input_revision' => (int) $request->input_revision,
            'current_version_id' => (int) $request->current_version_id,
            'input_versions' => RequestInputVersion::query()->where('request_id', $request->id)->count(),
            'ai_executions' => AiExecution::query()->where('request_id', $request->id)->count(),
            'reservations' => UsageReservation::query()->where('planning_request_id', $request->id)->count(),
            'render_runs' => DocumentRenderRun::query()->where('request_id', $request->id)->count(),
            'deliveries' => PlanningDelivery::query()->where('request_id', $request->id)->count(),
        ];

        $this->artisan('validation:export-standard-v2-qa', ['request_id' => $request->id])
            ->expectsOutputToContain('Standard v2 QA exportado.')
            ->expectsOutputToContain('request_id=' . $request->id)
            ->expectsOutputToContain('document_version_id=' . $version->id)
            ->expectsOutputToContain('source_content_hash=' . $version->content_hash)
            ->expectsOutputToContain('renderer_version=standard-v1.0.0')
            ->expectsOutputToContain('Validación no invasiva')
            ->assertSuccessful();

        $basePath = sprintf('qa/standard-v2/request-%d/version-%d', $request->id, $version->id);
        Storage::disk('private')->assertExists($basePath . '/planeacion-standard-v2.docx');
        Storage::disk('private')->assertExists($basePath . '/planeacion-standard-v2.pdf');

        $docx = Storage::disk('private')->get($basePath . '/planeacion-standard-v2.docx');
        $pdf = Storage::disk('private')->get($basePath . '/planeacion-standard-v2.pdf');
        $this->assertStringStartsWith("PK\x03\x04", $docx);
        $this->assertStringContainsString('Planeación DEMO', $docx);
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);

        $fresh = $request->fresh();
        $this->assertSame(PlanningRequestStatus::APROBADA, $fresh->status);
        $this->assertNull($fresh->format_version_id);
        $this->assertSame($before['input_revision'], (int) $fresh->input_revision);
        $this->assertSame($before['current_version_id'], (int) $fresh->current_version_id);
        $this->assertSame($before['input_versions'], RequestInputVersion::query()->where('request_id', $request->id)->count());
        $this->assertSame($before['ai_executions'], AiExecution::query()->where('request_id', $request->id)->count());
        $this->assertSame($before['reservations'], UsageReservation::query()->where('planning_request_id', $request->id)->count());
        $this->assertSame($before['render_runs'], DocumentRenderRun::query()->where('request_id', $request->id)->count());
        $this->assertSame($before['deliveries'], PlanningDelivery::query()->where('request_id', $request->id)->count());
    }
}
