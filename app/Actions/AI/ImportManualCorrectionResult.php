<?php

namespace App\Actions\AI;

use App\Enums\AiExecutionMode;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Enums\CorrectionRequestStatus;
use App\Enums\DocumentVersionStatus;
use App\Enums\OutboxEventType;
use App\Enums\PlanningRequestStatus;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use App\Models\AiManualPackage;
use App\Models\CorrectionRequest;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use App\Models\User;
use App\Services\AI\AuditPromptPolicy;
use App\Services\AI\AuditPromptSelector;
use App\Services\AI\CanonicalPlanCorrectionApplier;
use App\Services\AI\CorrectionInputBuilder;
use App\Services\AI\CorrectionResultValidator;
use App\Services\Planning\PlanningRequestStateMachine;
use App\Support\AI\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ImportManualCorrectionResult
{
    public function __construct(
        private CorrectionResultValidator $validator,
        private CorrectionInputBuilder $inputBuilder,
        private CanonicalPlanCorrectionApplier $applier,
        private AuditPromptSelector $auditPromptSelector,
        private AuditPromptPolicy $auditPromptPolicy,
        private PlanningRequestStateMachine $stateMachine,
    ) {}

    /** @param array<string,mixed> $payload */
    public function execute(
        AiExecution $execution,
        array $payload,
        ?string $provider = null,
        ?string $model = null,
        ?string $actualCost = null,
        ?string $costCurrency = null,
        ?User $actor = null,
    ): DocumentVersion {
        [$provider, $model, $actualCost, $costCurrency] = $this->normalizeMetadata($provider, $model, $actualCost, $costCurrency);
        $result = $this->validator->validate($payload);
        $sourcePayloadHash = CanonicalJson::hash($result->toArray());

        return DB::transaction(function () use (
            $execution,
            $result,
            $sourcePayloadHash,
            $provider,
            $model,
            $actualCost,
            $costCurrency,
            $actor,
        ): DocumentVersion {
            $locked = AiExecution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();
            if ($locked->stage !== AiExecutionStage::Correction || $locked->mode !== AiExecutionMode::Manual) {
                throw new AiPipelineException('AI_CORRECTION_RESULT_EXECUTION_INVALID');
            }

            if ($locked->status === AiExecutionStatus::Succeeded) {
                $existing = DocumentVersion::query()->whereKey($locked->resulting_version_id)->first();
                if (! $existing) {
                    throw new AiPipelineException('AI_CORRECTION_RESULT_VERSION_MISSING');
                }
                if ($existing->source_payload_hash !== $sourcePayloadHash) {
                    throw new AiPipelineException('AI_CORRECTION_RESULT_IDEMPOTENCY_CONFLICT');
                }
                return $existing;
            }

            if ($locked->status !== AiExecutionStatus::WaitingManual) {
                throw new AiPipelineException('AI_CORRECTION_RESULT_NOT_WAITING_MANUAL');
            }

            $manualPackage = AiManualPackage::query()->where('ai_execution_id', $locked->id)->first();
            if (! $manualPackage) {
                throw new AiPipelineException('AI_CORRECTION_MANUAL_PACKAGE_MISSING');
            }

            $request = PlanningRequest::query()->whereKey($locked->request_id)->lockForUpdate()->first();
            if (! $request
                || $request->status !== PlanningRequestStatus::CORRECCION_IA
                || (int) $request->input_revision !== (int) $locked->input_revision) {
                throw new AiPipelineException('AI_CORRECTION_RESULT_REQUEST_STALE');
            }

            $input = $this->inputBuilder->rebuildForExecution($locked->fresh(['request', 'promptVersion']));
            if ($result->sourceVersionId !== $input->sourceVersionId) {
                throw new AiPipelineException('AI_CORRECTION_RESULT_SOURCE_VERSION_MISMATCH');
            }

            $sourceVersion = DocumentVersion::query()->with('document')->whereKey($input->sourceVersionId)->firstOrFail();
            $auditPrompt = $this->auditPromptSelector->activeForUpdate();
            $this->auditPromptPolicy->assertReady($auditPrompt);

            $requestForAssembler = $request->fresh(['currentInputVersion']);
            if (! $requestForAssembler) {
                throw new AiPipelineException('AI_CORRECTION_RESULT_REQUEST_MISSING');
            }
            $corrected = $this->applier->apply($requestForAssembler, $input->canonicalPlan, $result, $input->sectionKeys);
            $canonicalPayload = $corrected->toArray();
            $contentHash = CanonicalJson::hash($canonicalPayload);

            $document = Document::query()->where('request_id', $request->id)->lockForUpdate()->firstOrFail();
            if ((int) $document->current_version_id !== (int) $sourceVersion->id) {
                throw new AiPipelineException('AI_CORRECTION_DOCUMENT_VERSION_STALE');
            }

            $nextNumber = ((int) $document->versions()->max('number')) + 1;
            $version = DocumentVersion::query()->create([
                'document_id' => $document->id,
                'number' => $nextNumber,
                'parent_version_id' => $sourceVersion->id,
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
            $locked->forceFill([
                'provider' => $provider,
                'model' => $model,
                'status' => AiExecutionStatus::Succeeded->value,
                'started_at' => $locked->started_at ?? $startedAt,
                'finished_at' => $finishedAt,
                'duration_ms' => max(0, (int) $startedAt->diffInMilliseconds($finishedAt)),
                'error_code' => null,
                'sanitized_error' => null,
                'actual_cost' => $actualCost,
                'cost_currency' => $costCurrency,
                'resulting_version_id' => $version->id,
            ])->save();

            if ($input->sourceKind === 'client') {
                $clientCorrection = CorrectionRequest::query()
                    ->whereKey($input->sourceCorrectionRequestId)
                    ->lockForUpdate()
                    ->first();
                if (! $clientCorrection
                    || $clientCorrection->status !== CorrectionRequestStatus::Processing
                    || (int) $clientCorrection->request_id !== (int) $request->id
                    || (int) $clientCorrection->source_version_id !== (int) $sourceVersion->id) {
                    throw new AiPipelineException('AI_CLIENT_CORRECTION_SOURCE_REQUEST_STALE');
                }
                $clientCorrection->forceFill([
                    'status' => CorrectionRequestStatus::Resolved->value,
                    'resolved_at' => now(),
                    'resolution' => 'correction_applied_reaudit_pending',
                    'resulting_version_id' => $version->id,
                ])->save();
            }

            $correlationId = strtolower(trim((string) ($locked->input_manifest['correlation_id'] ?? '')));
            if (! Str::isUuid($correlationId)) {
                throw new AiPipelineException('AI_CORRECTION_RESULT_CORRELATION_INVALID');
            }

            $auditOperationKey = sprintf(
                'planning-request:%d:audit:version:%d:prompt:%d',
                $request->id,
                $version->id,
                $auditPrompt->id,
            );
            $audit = AiExecution::query()->where('operation_key', $auditOperationKey)->lockForUpdate()->first();
            if (! $audit) {
                $audit = AiExecution::query()->create([
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

            $eventKey = 'ai-execution:' . $audit->id . ':audit-dispatch';
            $event = OutboxEvent::query()->where('event_key', $eventKey)->lockForUpdate()->first();
            if (! $event) {
                OutboxEvent::query()->create([
                    'event_key' => $eventKey,
                    'type' => OutboxEventType::PlanningAuditRequested->value,
                    'aggregate_id' => $request->id,
                    'payload' => [
                        'request_id' => (int) $request->id,
                        'ai_execution_id' => (int) $audit->id,
                        'input_revision' => (int) $request->input_revision,
                        'source_version_id' => (int) $version->id,
                        'correlation_id' => $correlationId,
                    ],
                    'published_at' => null,
                    'attempts' => 0,
                    'available_at' => now(),
                ]);
            }

            $this->stateMachine->assertCanTransition($request->status, PlanningRequestStatus::AUDITORIA_IA);
            $request->forceFill([
                'status' => PlanningRequestStatus::AUDITORIA_IA->value,
                'lock_version' => (int) $request->lock_version + 1,
            ])->save();
            $request->stateEvents()->create([
                'from_status' => PlanningRequestStatus::CORRECCION_IA->value,
                'to_status' => PlanningRequestStatus::AUDITORIA_IA->value,
                'actor_id' => $actor?->id,
                'actor_type' => $actor ? 'user' : 'system',
                'reason' => 'correction_result_imported_reaudit',
                'correlation_id' => $correlationId,
            ]);

            return $version->fresh(['document', 'aiExecution']);
        }, attempts: 3);
    }

    /** @return array{0:?string,1:?string,2:?string,3:?string} */
    private function normalizeMetadata(?string $provider, ?string $model, ?string $actualCost, ?string $costCurrency): array
    {
        $provider = $this->nullableText($provider, 128, 'AI_CORRECTION_PROVIDER_INVALID');
        $model = $this->nullableText($model, 128, 'AI_CORRECTION_MODEL_INVALID');
        if (($provider === null) !== ($model === null)) {
            throw new AiPipelineException('AI_CORRECTION_PROVIDER_MODEL_PAIR_REQUIRED');
        }

        $actualCost = $actualCost === null ? null : trim($actualCost);
        $costCurrency = $costCurrency === null ? null : strtoupper(trim($costCurrency));
        $actualCost = $actualCost === '' ? null : $actualCost;
        $costCurrency = $costCurrency === '' ? null : $costCurrency;
        if ($actualCost === null) {
            if ($costCurrency !== null) {
                throw new AiPipelineException('AI_CORRECTION_COST_CURRENCY_WITHOUT_COST');
            }
        } else {
            if (! is_numeric($actualCost) || (float) $actualCost < 0) {
                throw new AiPipelineException('AI_CORRECTION_ACTUAL_COST_INVALID');
            }
            if ($costCurrency === null || preg_match('/^[A-Z]{3}$/', $costCurrency) !== 1) {
                throw new AiPipelineException('AI_CORRECTION_COST_CURRENCY_INVALID');
            }
            if (preg_match('/^\d{1,10}(?:\.\d{1,8})?$/', $actualCost) !== 1) {
                throw new AiPipelineException('AI_CORRECTION_ACTUAL_COST_PRECISION_INVALID');
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
