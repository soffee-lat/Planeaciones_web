<?php

namespace Tests\Concerns;

use App\Actions\AI\RouteAuditResult;
use App\Actions\Documents\DispatchDocumentRendering;
use App\Actions\Documents\ProcessDocumentRenderRun;
use App\Enums\PlanningRequestStatus;
use Illuminate\Support\Facades\Queue;

trait CreatesRenderedPlanningScenario
{
    /** @return array{request:\App\Models\PlanningRequest,version:\App\Models\DocumentVersion,run:\App\Models\DocumentRenderRun} */
    protected function renderedPlanningScene(string $prefix = 'documents/delivery-test'): array
    {
        config([
            'documents.disk' => 'private',
            'documents.prefix' => $prefix,
            'documents.queue' => 'documents',
            'documents.result_retention_days' => 365,
        ]);
        Queue::fake();

        $scene = $this->succeededAuditScenario(true);
        $request = app(RouteAuditResult::class)->execute($scene['audit']->fresh());
        $this->assertSame(PlanningRequestStatus::APROBADA, $request->status);
        $run = app(DispatchDocumentRendering::class)->execute($request);
        $run = app(ProcessDocumentRenderRun::class)->execute($run);
        $this->assertSame(PlanningRequestStatus::LISTA_PARA_ENTREGAR, $run->request->status);

        return [
            'request' => $run->request->fresh(),
            'version' => $scene['version']->fresh(['document', 'outputFiles.file']),
            'run' => $run->fresh(),
        ];
    }
}
