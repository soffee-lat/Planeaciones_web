<?php

namespace App\Observers;

use App\Enums\ProductEventType;
use App\Models\DeliveryDownload;
use App\Models\ProductEvent;
use App\Services\Analytics\ProductEventRecorder;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeliveryDownloadProductEventObserver
{
    public function created(DeliveryDownload $download): void
    {
        $outputFormat = DB::table('delivery_files')
            ->where('delivery_id', $download->delivery_id)
            ->where('file_id', $download->file_id)
            ->value('output_format');

        if ($outputFormat !== 'docx') {
            return;
        }

        $download->loadMissing(['delivery.request.owner', 'delivery.renderRun']);
        $delivery = $download->delivery;
        $request = $delivery?->request;
        $owner = $request?->owner;

        if (! $delivery || ! $request || ! $owner || (int) $download->user_id !== (int) $owner->id) {
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

        try {
            $metadata = [
                'delivery_id' => (int) $delivery->id,
                'file_id' => (int) $download->file_id,
            ];
            if ($delivery->renderRun?->format_version_id) {
                $metadata['format_version_id'] = (int) $delivery->renderRun->format_version_id;
            }

            app(ProductEventRecorder::class)->record(
                $owner,
                ProductEventType::DocxDownloaded,
                request: $request,
                metadata: $metadata,
            );
        } catch (Throwable $error) {
            report($error);
        }
    }
}
