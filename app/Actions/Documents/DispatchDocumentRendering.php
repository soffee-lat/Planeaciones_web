<?php

namespace App\Actions\Documents;

use App\Enums\ApprovalKind;
use App\Enums\DocumentRenderStatus;
use App\Enums\PlanningRequestStatus;
use App\Exceptions\DocumentRenderException;
use App\Jobs\RenderPlanningDocument;
use App\Models\Approval;
use App\Models\DocumentRenderRun;
use App\Models\DocumentVersion;
use App\Models\PlanningRequest;
use App\Services\AI\RequestBlockManager;
use App\Services\Documents\DocumentRendererRegistry;
use App\Services\Documents\PlanningFormatResolver;
use App\Services\Planning\PlanningRequestStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class DispatchDocumentRendering
{
    public function __construct(
        private EnsureStandardFormat $ensureStandardFormat,
        private PlanningFormatResolver $formatResolver,
        private DocumentRendererRegistry $renderers,
        private PlanningRequestStateMachine $stateMachine,
        private RequestBlockManager $blocks,
    ) {}

    public function execute(PlanningRequest $request, ?string $correlationId = null): DocumentRenderRun
    {
        $correlationId ??= (string) Str::uuid();
        if (! Str::isUuid($correlationId)) {
            throw new DocumentRenderException('DOCUMENT_RENDER_CORRELATION_INVALID');
        }
        $correlationId = strtolower($correlationId);

        // Garantiza que el fallback global exista antes de resolver. Si la
        // solicitud usa un formato explícito/institucional, la prioridad del
        // resolver se conserva intacta.
        $this->ensureStandardFormat->execute();

        try {
            /** @var array{run:DocumentRenderRun,dispatch:bool} $result */
            $result = DB::transaction(function () use ($request, $correlationId): array {
            $fresh = PlanningRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $document = $fresh->document()->lockForUpdate()->first();
            if (! $document || $document->current_version_id === null) {
                throw new DocumentRenderException('DOCUMENT_RENDER_CURRENT_VERSION_MISSING');
            }

            $version = DocumentVersion::query()->whereKey($document->current_version_id)->lockForUpdate()->first();
            if (! $version || (int) $version->document_id !== (int) $document->id) {
                throw new DocumentRenderException('DOCUMENT_RENDER_CURRENT_VERSION_INVALID');
            }

            if (in_array($fresh->status, [
                PlanningRequestStatus::LISTA_PARA_ENTREGAR,
                PlanningRequestStatus::ENTREGADA,
                PlanningRequestStatus::COMPLETADA,
            ], true)) {
                $existing = DocumentRenderRun::query()
                    ->where('request_id', $fresh->id)
                    ->where('version_id', $version->id)
                    ->where('status', DocumentRenderStatus::Succeeded->value)
                    ->orderByDesc('id')
                    ->first();
                if (! $existing) {
                    throw new DocumentRenderException('DOCUMENT_RENDER_SUCCESS_RUN_MISSING');
                }
                return ['run' => $existing, 'dispatch' => false];
            }

            if ($fresh->status === PlanningRequestStatus::GENERANDO_DOCUMENTO) {
                $existing = DocumentRenderRun::query()
                    ->where('request_id', $fresh->id)
                    ->where('version_id', $version->id)
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();
                if (! $existing) {
                    throw new DocumentRenderException('DOCUMENT_RENDER_RUN_MISSING');
                }

                return [
                    'run' => $existing,
                    'dispatch' => $existing->status === DocumentRenderStatus::Failed,
                ];
            }

            if ($fresh->status !== PlanningRequestStatus::APROBADA) {
                throw new DocumentRenderException('DOCUMENT_RENDER_REQUEST_NOT_APPROVED');
            }

            $this->assertApprovals($fresh, $version);
            $formatVersion = $this->formatResolver->resolve($fresh);
            if (! $this->renderers->supports($formatVersion)) {
                throw new DocumentRenderException('DOCUMENT_RENDERER_NOT_SUPPORTED', $formatVersion->renderer);
            }

            $rendererVersion = $this->renderers->rendererVersion($formatVersion);
            $operationKey = sprintf(
                'planning-request:%d:render:version:%d:format:%d:renderer:%s',
                $fresh->id,
                $version->id,
                $formatVersion->id,
                $rendererVersion,
            );

            $run = DocumentRenderRun::query()->where('operation_key', $operationKey)->lockForUpdate()->first();
            if (! $run) {
                $run = DocumentRenderRun::query()->create([
                    'request_id' => $fresh->id,
                    'version_id' => $version->id,
                    'format_version_id' => $formatVersion->id,
                    'operation_key' => $operationKey,
                    'correlation_id' => $correlationId,
                    'renderer_version' => $rendererVersion,
                    'status' => DocumentRenderStatus::Pending->value,
                    'attempts' => 0,
                    'manifest' => (object) [],
                    'started_at' => null,
                    'finished_at' => null,
                    'last_error_code' => null,
                ]);
            }

            $this->stateMachine->assertCanTransition($fresh->status, PlanningRequestStatus::GENERANDO_DOCUMENTO);
            $fresh->forceFill([
                'status' => PlanningRequestStatus::GENERANDO_DOCUMENTO->value,
                'lock_version' => (int) $fresh->lock_version + 1,
            ])->save();
            $fresh->stateEvents()->create([
                'from_status' => PlanningRequestStatus::APROBADA->value,
                'to_status' => PlanningRequestStatus::GENERANDO_DOCUMENTO->value,
                'actor_id' => null,
                'actor_type' => 'system',
                'reason' => 'document_render_dispatched',
                'correlation_id' => $correlationId,
            ]);

            $this->blocks->resolve($fresh, 'format_pending', 'document_render');

            return ['run' => $run->fresh(['request', 'version', 'formatVersion']), 'dispatch' => true];
            }, attempts: 3);
        } catch (DocumentRenderException $e) {
            if ($e->errorCode === 'DOCUMENT_RENDERER_NOT_SUPPORTED') {
                $fresh = PlanningRequest::query()->find($request->id);
                if ($fresh) {
                    $this->blocks->open(
                        $fresh,
                        'format_pending',
                        'document_render',
                        ['error_code' => $e->errorCode, 'renderer' => $e->detail],
                        $correlationId,
                    );
                }
            }
            throw $e;
        }

        if ($result['dispatch']) {
            try {
                RenderPlanningDocument::dispatch($result['run']->id)
                    ->onQueue((string) config('documents.queue', 'documents'));
            } catch (Throwable $e) {
                $this->markQueueFailure($result['run'], $correlationId);
                throw new DocumentRenderException('DOCUMENT_RENDER_QUEUE_DISPATCH_FAILED');
            }
        }

        return $result['run']->fresh(['request', 'version', 'formatVersion']);
    }

    private function assertApprovals(PlanningRequest $request, DocumentVersion $version): void
    {
        $hasAi = Approval::query()
            ->where('request_id', $request->id)
            ->where('version_id', $version->id)
            ->where('kind', ApprovalKind::Ai->value)
            ->exists();
        $hasHuman = Approval::query()
            ->where('request_id', $request->id)
            ->where('version_id', $version->id)
            ->where('kind', ApprovalKind::Human->value)
            ->exists();

        if (! $hasAi || ($request->human_review_required_snapshot && ! $hasHuman)) {
            throw new DocumentRenderException('DOCUMENT_RENDER_APPROVALS_MISSING');
        }
    }

    private function markQueueFailure(DocumentRenderRun $run, string $correlationId): void
    {
        DB::transaction(function () use ($run, $correlationId): void {
            $locked = DocumentRenderRun::query()->whereKey($run->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === DocumentRenderStatus::Succeeded) {
                return;
            }
            $locked->forceFill([
                'status' => DocumentRenderStatus::Failed->value,
                'finished_at' => now(),
                'last_error_code' => 'DOCUMENT_RENDER_QUEUE_DISPATCH_FAILED',
            ])->save();
            $request = PlanningRequest::query()->whereKey($locked->request_id)->lockForUpdate()->first();
            if ($request) {
                $this->blocks->open(
                    $request,
                    'document_render_failed',
                    'document_render',
                    ['error_code' => 'DOCUMENT_RENDER_QUEUE_DISPATCH_FAILED'],
                    $correlationId,
                );
            }
        });
    }
}
