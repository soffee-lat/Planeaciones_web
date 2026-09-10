<?php

namespace App\Actions\Documents;

use App\Enums\DocumentRenderStatus;
use App\Enums\PlanningRequestStatus;
use App\Exceptions\DocumentRenderException;
use App\Models\DocumentRenderRun;
use App\Models\DocumentVersion;
use App\Models\FormatVersion;
use App\Models\PlanningRequest;
use App\Services\AI\RequestBlockManager;
use App\Services\Documents\DocumentOutputStorage;
use App\Services\Documents\DocumentRendererRegistry;
use App\Services\Planning\PlanningRequestStateMachine;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProcessDocumentRenderRun
{
    public function __construct(
        private DocumentRendererRegistry $renderers,
        private DocumentOutputStorage $storage,
        private PlanningRequestStateMachine $stateMachine,
        private RequestBlockManager $blocks,
    ) {}

    public function execute(DocumentRenderRun|int $run): DocumentRenderRun
    {
        $runId = $run instanceof DocumentRenderRun ? $run->id : $run;

        try {
            return DB::transaction(function () use ($runId): DocumentRenderRun {
                $locked = DocumentRenderRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
                if ($locked->status === DocumentRenderStatus::Succeeded) {
                    return $locked->fresh(['request', 'version', 'formatVersion']);
                }

                $request = PlanningRequest::query()->whereKey($locked->request_id)->lockForUpdate()->firstOrFail();
                $version = DocumentVersion::query()->with('document')->whereKey($locked->version_id)->lockForUpdate()->firstOrFail();
                $formatVersion = FormatVersion::query()->with('format')->whereKey($locked->format_version_id)->lockForUpdate()->firstOrFail();

                if ($request->status !== PlanningRequestStatus::GENERANDO_DOCUMENTO
                    || ! $version->document
                    || (int) $version->document->request_id !== (int) $request->id
                    || (int) $version->document->current_version_id !== (int) $version->id
                    || $formatVersion->published_at === null
                    || ! $this->renderers->supports($formatVersion)
                    || $this->renderers->rendererVersion($formatVersion) !== $locked->renderer_version) {
                    throw new DocumentRenderException('DOCUMENT_RENDER_RUN_STALE');
                }

                $locked->forceFill([
                    'status' => DocumentRenderStatus::Running->value,
                    'attempts' => (int) $locked->attempts + 1,
                    'started_at' => $locked->started_at ?? now(),
                    'finished_at' => null,
                    'last_error_code' => null,
                ])->save();

                $artifacts = $this->renderers->render($version, $formatVersion);
                $outputs = $this->storage->persist($locked, $artifacts);
                if (count($outputs) !== 2
                    || array_column($outputs, 'format') !== ['docx', 'pdf']) {
                    throw new DocumentRenderException('DOCUMENT_RENDER_OUTPUT_SET_INVALID');
                }

                $manifest = [
                    'schema_version' => 'document_render_manifest_v1',
                    'request_id' => (int) $request->id,
                    'version_id' => (int) $version->id,
                    'format_version_id' => (int) $formatVersion->id,
                    'renderer_version' => $locked->renderer_version,
                    'source_content_hash' => $version->content_hash,
                    'outputs' => $outputs,
                ];

                $locked->forceFill([
                    'status' => DocumentRenderStatus::Succeeded->value,
                    'manifest' => $manifest,
                    'finished_at' => now(),
                    'last_error_code' => null,
                ])->save();

                $this->stateMachine->assertCanTransition($request->status, PlanningRequestStatus::LISTA_PARA_ENTREGAR);
                $request->forceFill([
                    'status' => PlanningRequestStatus::LISTA_PARA_ENTREGAR->value,
                    'lock_version' => (int) $request->lock_version + 1,
                ])->save();
                $request->stateEvents()->create([
                    'from_status' => PlanningRequestStatus::GENERANDO_DOCUMENTO->value,
                    'to_status' => PlanningRequestStatus::LISTA_PARA_ENTREGAR->value,
                    'actor_id' => null,
                    'actor_type' => 'system',
                    'reason' => 'document_render_succeeded',
                    'correlation_id' => $locked->correlation_id,
                ]);

                $this->blocks->resolve($request, 'document_render_failed', 'document_render');

                return $locked->fresh(['request', 'version', 'formatVersion']);
            }, attempts: 3);
        } catch (Throwable $e) {
            $errorCode = $e instanceof DocumentRenderException
                ? $e->errorCode
                : 'DOCUMENT_RENDER_PROCESSING_FAILED';
            $this->markFailed($runId, $errorCode);

            if ($e instanceof DocumentRenderException) {
                throw $e;
            }
            report($e);
            throw new DocumentRenderException($errorCode);
        }
    }

    private function markFailed(int $runId, string $errorCode): void
    {
        DB::transaction(function () use ($runId, $errorCode): void {
            $run = DocumentRenderRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run || $run->status === DocumentRenderStatus::Succeeded) {
                return;
            }
            $run->forceFill([
                'status' => DocumentRenderStatus::Failed->value,
                'finished_at' => now(),
                'last_error_code' => $errorCode,
            ])->save();

            $request = PlanningRequest::query()->whereKey($run->request_id)->lockForUpdate()->first();
            if ($request) {
                $this->blocks->open(
                    $request,
                    'document_render_failed',
                    'document_render',
                    ['error_code' => $errorCode, 'attempts' => (int) $run->attempts],
                    $run->correlation_id,
                );
            }
        });
    }
}
