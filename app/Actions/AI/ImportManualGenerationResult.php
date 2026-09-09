<?php

namespace App\Actions\AI;

use App\Data\Planning\GeneratedPlanDraft;
use App\Enums\AiExecutionMode;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Enums\DocumentVersionStatus;
use App\Enums\PlanningRequestStatus;
use App\Enums\OutboxEventType;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use App\Models\AiManualPackage;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use App\Models\User;
use App\Services\AI\AuditPromptSelector;
use App\Services\AI\AuditPromptPolicy;
use App\Services\AI\CanonicalPlanAssembler;
use App\Services\AI\GeneratedPlanDraftValidator;
use App\Services\Planning\PlanningRequestStateMachine;
use App\Support\AI\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ImportManualGenerationResult
{
    public function __construct(
        private GeneratedPlanDraftValidator $draftValidator,
        private CanonicalPlanAssembler $canonicalAssembler,
        private AuditPromptSelector $auditPromptSelector,
        private AuditPromptPolicy $auditPromptPolicy,
        private PlanningRequestStateMachine $stateMachine,
    ) {}

    /**
     * @param array<string,mixed> $payload GeneratedPlanDraftV1 devuelto por el operador/proveedor.
     */
    public function execute(
        AiExecution $execution,
        array $payload,
        ?string $provider = null,
        ?string $model = null,
        ?string $actualCost = null,
        ?string $costCurrency = null,
        ?User $actor = null,
    ): DocumentVersion {
        [$provider, $model, $actualCost, $costCurrency] = $this->normalizeMetadata(
            $provider,
            $model,
            $actualCost,
            $costCurrency,
        );

        $draft = $this->draftValidator->validate($payload);
        $sourcePayloadHash = CanonicalJson::hash($draft->toArray());

        return DB::transaction(function () use (
            $execution,
            $draft,
            $sourcePayloadHash,
            $provider,
            $model,
            $actualCost,
            $costCurrency,
            $actor,
        ): DocumentVersion {
            /** @var AiExecution $locked */
            $locked = AiExecution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();

            if ($locked->stage !== AiExecutionStage::Generation || $locked->mode !== AiExecutionMode::Manual) {
                throw new AiPipelineException('AI_GENERATION_RESULT_EXECUTION_INVALID');
            }

            if ($locked->status === AiExecutionStatus::Succeeded) {
                /** @var DocumentVersion|null $existing */
                $existing = DocumentVersion::query()->whereKey($locked->resulting_version_id)->first();
                if (! $existing) {
                    throw new AiPipelineException('AI_GENERATION_RESULT_VERSION_MISSING');
                }
                if ($existing->source_payload_hash !== $sourcePayloadHash) {
                    throw new AiPipelineException('AI_GENERATION_RESULT_IDEMPOTENCY_CONFLICT');
                }

                return $existing;
            }

            if ($locked->status !== AiExecutionStatus::WaitingManual) {
                throw new AiPipelineException('AI_GENERATION_RESULT_NOT_WAITING_MANUAL');
            }

            /** @var AiManualPackage|null $manualPackage */
            $manualPackage = AiManualPackage::query()
                ->where('ai_execution_id', $locked->id)
                ->first();
            if (! $manualPackage) {
                throw new AiPipelineException('AI_GENERATION_MANUAL_PACKAGE_MISSING');
            }

            /** @var PlanningRequest|null $request */
            $request = PlanningRequest::query()->whereKey($locked->request_id)->lockForUpdate()->first();
            if (! $request
                || $request->status !== PlanningRequestStatus::GENERACION_IA
                || $request->commercial_authorized_at === null
                || (int) $request->input_revision !== (int) $locked->input_revision
                || (int) ($locked->input_manifest['request_input_version_id'] ?? 0) !== (int) $request->current_version_id) {
                throw new AiPipelineException('AI_GENERATION_RESULT_REQUEST_STALE');
            }

            $correlationId = strtolower(trim((string) ($locked->input_manifest['correlation_id'] ?? '')));
            if (! Str::isUuid($correlationId)) {
                throw new AiPipelineException('AI_GENERATION_RESULT_CORRELATION_INVALID');
            }

            // La solicitud solo avanza a AUDITORIA_IA si existe una instrucción
            // de auditoría publicada exacta que pueda quedar congelada en la
            // siguiente AiExecution. No se ejecuta todavía en 4C.
            $auditPrompt = $this->auditPromptSelector->activeForUpdate();
            // 4D exige que la ejecución de auditoría nazca con un contrato
            // procesable. Un prompt publicado pero incompatible no debe dejar
            // la solicitud atrapada en AUDITORIA_IA con identidad inmutable.
            $this->auditPromptPolicy->assertReady($auditPrompt);

            $requestForAssembler = $request->fresh(['currentInputVersion']);
            if (! $requestForAssembler) {
                throw new AiPipelineException('AI_GENERATION_RESULT_REQUEST_MISSING');
            }
            $canonical = $this->canonicalAssembler->assemble($requestForAssembler, $draft);
            $canonicalPayload = $canonical->toArray();
            $contentHash = CanonicalJson::hash($canonicalPayload);

            /** @var Document|null $document */
            $document = Document::query()->where('request_id', $request->id)->lockForUpdate()->first();
            if (! $document) {
                $document = Document::query()->create([
                    'request_id' => $request->id,
                    'owner_id' => $request->owner_id,
                    'title' => trim((string) $canonicalPayload['planning']['title']),
                    'current_version_id' => null,
                ]);
                $document = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            }

            $nextNumber = ((int) $document->versions()->max('number')) + 1;
            $parentVersionId = $document->current_version_id;

            /** @var DocumentVersion $version */
            $version = DocumentVersion::query()->create([
                'document_id' => $document->id,
                'number' => $nextNumber,
                'parent_version_id' => $parentVersionId,
                'input_revision' => (int) $request->input_revision,
                'content' => $canonicalPayload,
                'content_hash' => $contentHash,
                'source_payload_hash' => $sourcePayloadHash,
                'created_by' => $actor?->id,
                'ai_execution_id' => $locked->id,
                'status' => DocumentVersionStatus::Validated->value,
            ]);

            $document->forceFill([
                'title' => trim((string) $canonicalPayload['planning']['title']),
                'current_version_id' => $version->id,
            ])->save();

            $finishedAt = now();
            $startedAt = $locked->started_at ?? $manualPackage->created_at ?? $locked->created_at ?? $finishedAt;
            $durationMs = max(0, (int) $startedAt->diffInMilliseconds($finishedAt));

            $locked->forceFill([
                'provider' => $provider,
                'model' => $model,
                'status' => AiExecutionStatus::Succeeded->value,
                'started_at' => $locked->started_at ?? $startedAt,
                'finished_at' => $finishedAt,
                'duration_ms' => $durationMs,
                'error_code' => null,
                'sanitized_error' => null,
                'actual_cost' => $actualCost,
                'cost_currency' => $costCurrency,
                'resulting_version_id' => $version->id,
            ])->save();

            $auditOperationKey = sprintf(
                'planning-request:%d:audit:version:%d:prompt:%d',
                $request->id,
                $version->id,
                $auditPrompt->id,
            );

            $auditExecution = AiExecution::query()
                ->where('operation_key', $auditOperationKey)
                ->lockForUpdate()
                ->first();

            if (! $auditExecution) {
                $auditExecution = AiExecution::query()->create([
                    'request_id' => $request->id,
                    'format_version_id' => null,
                    'stage' => AiExecutionStage::Audit->value,
                    'mode' => AiExecutionMode::Manual->value,
                    'provider' => null,
                    'model' => null,
                    'prompt_version_id' => $auditPrompt->id,
                    'input_revision' => (int) $request->input_revision,
                    'input_manifest' => [
                        'schema_version' => 1,
                        'request_id' => (int) $request->id,
                        'input_revision' => (int) $request->input_revision,
                        'source_version_id' => (int) $version->id,
                        'source_content_hash' => $contentHash,
                        'correlation_id' => $correlationId,
                    ],
                    'rendered_prompt_hash' => null,
                    'private_payload_file_id' => null,
                    'operation_key' => $auditOperationKey,
                    'status' => AiExecutionStatus::Pending->value,
                    'started_at' => null,
                    'finished_at' => null,
                    'duration_ms' => null,
                    'error_code' => null,
                    'sanitized_error' => null,
                    'estimated_cost' => null,
                    'actual_cost' => null,
                    'cost_currency' => null,
                    'resulting_version_id' => null,
                    'audit_report' => null,
                ]);
            }

            if ($auditExecution->request_id !== $request->id
                || $auditExecution->stage !== AiExecutionStage::Audit
                || (int) $auditExecution->input_revision !== (int) $request->input_revision
                || (int) ($auditExecution->input_manifest['source_version_id'] ?? 0) !== (int) $version->id) {
                throw new AiPipelineException('AI_AUDIT_EXECUTION_IDEMPOTENCY_CONFLICT');
            }

            $auditEventKey = 'ai-execution:' . $auditExecution->id . ':audit-dispatch';
            /** @var OutboxEvent|null $auditEvent */
            $auditEvent = OutboxEvent::query()->where('event_key', $auditEventKey)->lockForUpdate()->first();
            if (! $auditEvent) {
                $auditEvent = OutboxEvent::query()->create([
                    'event_key' => $auditEventKey,
                    'type' => OutboxEventType::PlanningAuditRequested->value,
                    'aggregate_id' => $request->id,
                    'payload' => [
                        'request_id' => (int) $request->id,
                        'ai_execution_id' => (int) $auditExecution->id,
                        'input_revision' => (int) $request->input_revision,
                        'source_version_id' => (int) $version->id,
                        'correlation_id' => $correlationId,
                    ],
                    'published_at' => null,
                    'attempts' => 0,
                    'available_at' => now(),
                ]);
            }
            if ($auditEvent->type !== OutboxEventType::PlanningAuditRequested
                || (int) $auditEvent->aggregate_id !== (int) $request->id
                || (int) ($auditEvent->payload['ai_execution_id'] ?? 0) !== (int) $auditExecution->id
                || (int) ($auditEvent->payload['source_version_id'] ?? 0) !== (int) $version->id) {
                throw new AiPipelineException('AI_AUDIT_OUTBOX_IDEMPOTENCY_CONFLICT');
            }

            $this->stateMachine->assertCanTransition($request->status, PlanningRequestStatus::AUDITORIA_IA);
            $request->forceFill([
                'status' => PlanningRequestStatus::AUDITORIA_IA->value,
                'lock_version' => (int) $request->lock_version + 1,
            ])->save();
            $request->stateEvents()->create([
                'from_status' => PlanningRequestStatus::GENERACION_IA->value,
                'to_status' => PlanningRequestStatus::AUDITORIA_IA->value,
                'actor_id' => $actor?->id,
                'actor_type' => $actor ? 'user' : 'system',
                'reason' => 'generation_result_imported',
                'correlation_id' => $correlationId,
            ]);

            return $version->fresh(['document', 'aiExecution']);
        }, attempts: 3);
    }

    /** @return array{0:?string,1:?string,2:?string,3:?string} */
    private function normalizeMetadata(
        ?string $provider,
        ?string $model,
        ?string $actualCost,
        ?string $costCurrency,
    ): array {
        $provider = $this->nullableText($provider, 128, 'AI_GENERATION_PROVIDER_INVALID');
        $model = $this->nullableText($model, 128, 'AI_GENERATION_MODEL_INVALID');

        if (($provider === null) !== ($model === null)) {
            throw new AiPipelineException('AI_GENERATION_PROVIDER_MODEL_PAIR_REQUIRED');
        }

        $actualCost = $actualCost === null ? null : trim($actualCost);
        $costCurrency = $costCurrency === null ? null : strtoupper(trim($costCurrency));

        if ($actualCost === '') {
            $actualCost = null;
        }
        if ($costCurrency === '') {
            $costCurrency = null;
        }

        if ($actualCost === null) {
            if ($costCurrency !== null) {
                throw new AiPipelineException('AI_GENERATION_COST_CURRENCY_WITHOUT_COST');
            }
        } else {
            if (! is_numeric($actualCost) || (float) $actualCost < 0) {
                throw new AiPipelineException('AI_GENERATION_ACTUAL_COST_INVALID');
            }
            if ($costCurrency === null || preg_match('/^[A-Z]{3}$/', $costCurrency) !== 1) {
                throw new AiPipelineException('AI_GENERATION_COST_CURRENCY_INVALID');
            }
            // PostgreSQL DECIMAL(18,8): rechazar antes de tocar historial.
            if (preg_match('/^\d{1,10}(?:\.\d{1,8})?$/', $actualCost) !== 1) {
                throw new AiPipelineException('AI_GENERATION_ACTUAL_COST_PRECISION_INVALID');
            }
        }

        return [$provider, $model, $actualCost, $costCurrency];
    }

    private function nullableText(?string $value, int $max, string $errorCode): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new AiPipelineException($errorCode);
        }

        return $value;
    }
}
