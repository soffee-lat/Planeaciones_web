<?php

namespace Tests\Feature\Documents;

use App\Actions\AI\RouteAuditResult;
use App\Actions\Documents\DispatchDocumentRendering;
use App\Actions\Documents\ProcessDocumentRenderRun;
use App\Enums\PlanningRequestStatus;
use App\Models\AiExecution;
use App\Models\DocumentVersion;
use App\Models\PlanningRequest;
use App\Models\RequestInputVersion;
use App\Models\UsageReservation;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesInstitutionalFormatScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Feature\PedagogyTestCase;

class ExportFormatIndependenceTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesInstitutionalFormatScenario;
    use CreatesManualAiPipelineScenario;

    public function test_cambiar_formato_de_exportacion_no_regenera_ni_consume_otra_unidad(): void
    {
        Queue::fake();
        $scene = $this->approvedScene();
        $request = $scene['request']->fresh();
        $canonical = $scene['version']->fresh();

        $before = [
            'ai_execution_ids' => AiExecution::query()
                ->where('request_id', $request->id)
                ->orderBy('id')
                ->pluck('id')
                ->all(),
            'input_version_ids' => RequestInputVersion::query()
                ->where('request_id', $request->id)
                ->orderBy('id')
                ->pluck('id')
                ->all(),
            'input_revision' => (int) $request->input_revision,
            'current_input_version_id' => (int) $request->current_version_id,
            'usage_reservations' => UsageReservation::query()
                ->where('request_id', $request->id)
                ->orderBy('id')
                ->get()
                ->map(fn (UsageReservation $reservation): array => $reservation->getRawOriginal())
                ->all(),
            'document_version_ids' => DocumentVersion::query()
                ->where('document_id', $canonical->document_id)
                ->orderBy('id')
                ->pluck('id')
                ->all(),
            'canonical_content_hash' => $canonical->content_hash,
            'canonical_content' => $canonical->content,
        ];

        $institutional = $this->publishedInstitutionalFormat($request->owner_id);
        $request->update(['format_version_id' => $institutional->id]);

        $selected = $request->fresh();
        $this->assertSame($before['input_revision'], (int) $selected->input_revision);
        $this->assertSame($before['current_input_version_id'], (int) $selected->current_version_id);

        $run = app(DispatchDocumentRendering::class)->execute($selected);
        $this->assertSame($institutional->id, $run->format_version_id);
        $done = app(ProcessDocumentRenderRun::class)->execute($run);

        $after = $request->fresh();
        $canonicalAfter = $canonical->fresh();

        $this->assertSame(PlanningRequestStatus::LISTA_PARA_ENTREGAR, $done->request->status);
        $this->assertSame($institutional->id, $after->format_version_id);
        $this->assertSame($before['input_revision'], (int) $after->input_revision);
        $this->assertSame($before['current_input_version_id'], (int) $after->current_version_id);
        $this->assertSame(
            $before['ai_execution_ids'],
            AiExecution::query()->where('request_id', $request->id)->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(
            $before['input_version_ids'],
            RequestInputVersion::query()->where('request_id', $request->id)->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame(
            $before['usage_reservations'],
            UsageReservation::query()
                ->where('request_id', $request->id)
                ->orderBy('id')
                ->get()
                ->map(fn (UsageReservation $reservation): array => $reservation->getRawOriginal())
                ->all(),
        );
        $this->assertSame(
            $before['document_version_ids'],
            DocumentVersion::query()->where('document_id', $canonical->document_id)->orderBy('id')->pluck('id')->all(),
        );
        $this->assertSame($before['canonical_content_hash'], $canonicalAfter->content_hash);
        $this->assertSame($before['canonical_content'], $canonicalAfter->content);
    }

    /** @return array{request:PlanningRequest,version:DocumentVersion} */
    private function approvedScene(): array
    {
        config([
            'documents.disk' => 'private',
            'documents.prefix' => 'documents/export-format-independence-test',
            'documents.queue' => 'documents',
        ]);

        $scene = $this->succeededAuditScenario(true);
        $request = app(RouteAuditResult::class)->execute($scene['audit']->fresh());
        $this->assertSame(PlanningRequestStatus::APROBADA, $request->status);

        return [
            'request' => $request,
            'version' => $scene['version']->fresh(['document']),
        ];
    }
}
