<?php

namespace App\Actions\Documents;

use App\Enums\FileCategory;
use App\Enums\FileScanStatus;
use App\Enums\PlanningRequestStatus;
use App\Exceptions\DocumentDeliveryException;
use App\Models\DocumentRenderRun;
use App\Models\DocumentVersionFile;
use App\Models\PlanningDelivery;
use App\Models\PlanningRequest;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Planning\PlanningRequestStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class PublishPlanningDelivery
{
    public function __construct(private PlanningRequestStateMachine $stateMachine) {}

    public function execute(PlanningRequest|int $request, ?User $actor = null, ?string $correlationId = null): PlanningDelivery
    {
        $requestId = $request instanceof PlanningRequest ? $request->id : $request;

        return DB::transaction(function () use ($requestId, $actor, $correlationId): PlanningDelivery {
            $locked = PlanningRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();
            $document = $locked->document()->with('currentVersion')->lockForUpdate()->first();
            $version = $document?->currentVersion;
            if (! $version) {
                throw new DocumentDeliveryException('DELIVERY_CURRENT_VERSION_REQUIRED');
            }

            $existing = PlanningDelivery::query()
                ->where('request_id', $locked->id)
                ->where('version_id', $version->id)
                ->latest('id')
                ->first();
            if ($existing && in_array($locked->status, [PlanningRequestStatus::ENTREGADA, PlanningRequestStatus::COMPLETADA, PlanningRequestStatus::CORRECCION_SOLICITADA], true)) {
                return $existing->load('files');
            }
            if ($locked->status !== PlanningRequestStatus::LISTA_PARA_ENTREGAR) {
                throw new DocumentDeliveryException('DELIVERY_REQUEST_NOT_READY');
            }

            $run = DocumentRenderRun::query()
                ->where('request_id', $locked->id)
                ->where('version_id', $version->id)
                ->where('status', 'succeeded')
                ->latest('id')
                ->lockForUpdate()
                ->first();
            if (! $run) {
                throw new DocumentDeliveryException('DELIVERY_RENDER_REQUIRED');
            }

            $links = DocumentVersionFile::query()
                ->with('file')
                ->where('version_id', $version->id)
                ->where('renderer_version', $run->renderer_version)
                ->whereIn('output_format', ['docx', 'pdf'])
                ->get();
            if ($links->count() !== 2 || $links->pluck('output_format')->map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v)->sort()->values()->all() !== ['docx', 'pdf']) {
                throw new DocumentDeliveryException('DELIVERY_OUTPUT_SET_INVALID');
            }

            $retentionDays = (int) config('documents.result_retention_days', 365);
            if ($retentionDays < 1) {
                throw new DocumentDeliveryException('DELIVERY_RETENTION_CONFIG_INVALID');
            }
            $deliveredAt = now();
            $retentionUntil = $deliveredAt->copy()->addDays($retentionDays);

            foreach ($links as $link) {
                $file = $link->file;
                if (! $file
                    || $file->category !== FileCategory::Result
                    || $file->scan_status !== FileScanStatus::Clean
                    || (int) $file->owner_id !== (int) $locked->owner_id
                    || (int) $file->request_id !== (int) $locked->id
                    || $file->purged_at !== null
                    || ! Storage::disk($file->disk)->exists($file->path)
                    || hash('sha256', Storage::disk($file->disk)->get($file->path)) !== $file->sha256) {
                    throw new DocumentDeliveryException('DELIVERY_FILE_NOT_AVAILABLE');
                }
            }

            $operationKey = sprintf('planning-request:%d:delivery:version:%d:renderer:%s', $locked->id, $version->id, $run->renderer_version);
            $correlationId ??= (string) Str::uuid();
            $delivery = PlanningDelivery::query()->firstOrCreate(
                ['idempotency_key' => $operationKey],
                [
                    'request_id' => $locked->id,
                    'version_id' => $version->id,
                    'render_run_id' => $run->id,
                    'delivered_at' => $deliveredAt,
                    'created_by' => $actor?->id,
                    'correlation_id' => $correlationId,
                ],
            );
            if ((int) $delivery->request_id !== (int) $locked->id || (int) $delivery->version_id !== (int) $version->id) {
                throw new DocumentDeliveryException('DELIVERY_IDEMPOTENCY_CONFLICT');
            }

            foreach ($links as $link) {
                $file = StoredFile::query()->whereKey($link->file_id)->lockForUpdate()->firstOrFail();
                if ($file->retention_until === null || $file->retention_until->lt($retentionUntil)) {
                    $file->forceFill(['retention_until' => $retentionUntil])->save();
                }
                DB::table('delivery_files')->insertOrIgnore([
                    'delivery_id' => $delivery->id,
                    'file_id' => $file->id,
                    'output_format' => $link->output_format instanceof \BackedEnum ? $link->output_format->value : $link->output_format,
                    'renderer_version' => $run->renderer_version,
                    'created_at' => $deliveredAt,
                ]);
            }

            $this->stateMachine->assertCanTransition($locked->status, PlanningRequestStatus::ENTREGADA);
            $locked->forceFill([
                'status' => PlanningRequestStatus::ENTREGADA->value,
                'lock_version' => (int) $locked->lock_version + 1,
            ])->save();
            $locked->stateEvents()->create([
                'from_status' => PlanningRequestStatus::LISTA_PARA_ENTREGAR->value,
                'to_status' => PlanningRequestStatus::ENTREGADA->value,
                'actor_id' => $actor?->id,
                'actor_type' => $actor ? 'user' : 'system',
                'reason' => 'delivery_published',
                'correlation_id' => $delivery->correlation_id,
            ]);

            return $delivery->fresh('files');
        }, attempts: 3);
    }
}
