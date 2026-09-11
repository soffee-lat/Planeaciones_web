<?php

namespace App\Observers;

use App\Enums\AiExecutionStage;
use App\Enums\ProductEventType;
use App\Models\DocumentVersion;
use App\Models\ProductEvent;
use App\Services\Analytics\ProductEventRecorder;
use Throwable;

final class DocumentVersionProductEventObserver
{
    public function created(DocumentVersion $version): void
    {
        if (! $version->ai_execution_id) {
            return;
        }

        $version->loadMissing(['aiExecution', 'document.request.owner']);
        $execution = $version->aiExecution;
        $request = $version->document?->request;
        $owner = $request?->owner;

        if (! $execution || $execution->stage !== AiExecutionStage::Generation || ! $request || ! $owner) {
            return;
        }

        $isValidationFlow = ProductEvent::query()
            ->where('planning_request_id', $request->id)
            ->where('event_type', ProductEventType::PlanningStarted->value)
            ->where('metadata->entry_surface', 'curricular_validation_v1')
            ->exists();

        if (! $isValidationFlow) {
            return;
        }

        $alreadyRecorded = ProductEvent::query()
            ->where('planning_request_id', $request->id)
            ->where('event_type', ProductEventType::PlanGenerated->value)
            ->where('metadata->document_version_id', $version->id)
            ->exists();

        if ($alreadyRecorded) {
            return;
        }

        try {
            app(ProductEventRecorder::class)->record(
                $owner,
                ProductEventType::PlanGenerated,
                request: $request,
                metadata: [
                    'document_version_id' => (int) $version->id,
                    'renderer' => 'canonical_plan_v1',
                ],
            );
        } catch (Throwable $error) {
            report($error);
        }
    }
}
